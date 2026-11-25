<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Symfony\Component\Process\Process;

/** Continuous linear-response route. Exact represented budget/group checks, independent LP dual check. */
final class TypedLinearLpSolver
{
    public function solve(DecisionProblem $problem, array $models): array
    {
        $python = config('solver.python');
        if (! is_string($python) || ! str_starts_with($python, '/') || ! is_executable($python)) {
            return ['status' => 'solver_unavailable'];
        }
        $p = $problem->jsonSerialize();
        $variables = [];
        $slopes = [];
        foreach ($p['variables'] as $v) {
            $curve = $models[$v['id']]['curve'];
            if ($curve->name() !== 'linear') {
                return ['status' => 'linear_curve_required'];
            }
            $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.;
            $slope = $curve->parameters()['a'] * $scale;
            if (! is_finite($slope) || $slope < 0) {
                return ['status' => 'invalid_linear_response'];
            }
            foreach (['minimum', 'maximum'] as $key) {
                $f = (float) $v[$key];
                if (! is_finite($f) || $f > 1e12 || Binary64Boundary::loss((string) $v[$key], $f)->isGreaterThan('0.0001')) {
                    return ['status' => 'variable_bound_conversion_loss'];
                }
            }
            if ((float) $v['minimum'] < (method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0) || (float) $v['maximum'] > $curve->supportMaxSpend()) {
                return ['status' => 'bounds_outside_model_support'];
            }
            $variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum'], 'slope' => $slope];
            $slopes[] = $slope;
        }
        $groups = [];
        foreach ($p['groups'] as $g) {
            foreach (['minimum', 'maximum'] as $key) {
                $f = (float) $g[$key];
                if (! is_finite($f) || $f > 1e12 || Binary64Boundary::loss((string) $g[$key], $f)->isGreaterThan('0.0001')) {
                    return ['status' => 'group_bound_conversion_loss'];
                }
            }
            $groups[] = ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']];
        }
        $input = ['variables' => $variables, 'groups' => $groups, 'objective' => $p['objective'], 'target_optimization' => $p['target_optimization'] ?? null, 'budget_cap' => (float) $p['budget_cap'],
            'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => (float) $p['minimum_response'], 'target' => isset($p['target']) ? (float) $p['target'] : null];
        try {
            $process = new Process([$python, base_path('solver/typed_linear_lp.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $r = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'solver_failed_or_timeout'];
        }
        if (($r['status'] ?? null) !== 'solved') {
            return $r;
        }
        if (($r['version'] ?? null) !== 'typed-linear-lp-v1' || ($r['solver'] ?? null) !== 'scipy.optimize.linprog/HiGHS' || ! is_array($r['trace'] ?? null) || $r['trace'] === []) {
            return ['status' => 'invalid_solver_certificate'];
        }
        $allocation = [];
        foreach ($variables as $v) {
            $x = $r['allocation'][$v['id']] ?? null;
            if ((! is_float($x) && ! is_int($x)) || ! is_finite((float) $x) || $x < 0) {
                return ['status' => 'invalid_solver_allocation'];
            }
            // Decode the actual binary64 bits to a terminating decimal, never choose a display/financial rounding scale.
            $allocation[$v['id']] = (string) Binary64Boundary::rational((float) $x)->toBigDecimal();
        }
        if (array_diff(array_keys($r['allocation']), array_keys($allocation))) {
            return ['status' => 'solver_population_mismatch'];
        }
        $recovery = (new ExactLinearVertexRecovery)->recover($problem, $r['allocation'], $slopes);
        if ($recovery['status'] === 'recovered_candidate') {
            $allocation = $recovery['allocation'];
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation, false);
        if ($check['status'] !== 'checked') {
            return ['status' => 'independent_check_failed_no_rounding_repair', 'check' => $check, 'recovery' => $recovery];
        }
        $representedCheck = (new ExactLinearModelCheck)->check($problem, $models, $allocation);
        if ($representedCheck['status'] !== 'checked') {
            return ['status' => 'exact_represented_linear_feasibility_rejected', 'check' => $representedCheck];
        }
        // Build inequalities independently. The script-returned matrix is diagnostic only, never the checker input.
        $n = count($variables);
        $matrix = [array_fill(0, $n, 1.), array_fill(0, $n, -1.), array_map(fn ($s) => -$s, $slopes)];
        $rhs = [(float) $p['budget_cap'], -(float) $p['minimum_spend'], -(float) $p['minimum_response']];
        foreach ($groups as $g) {
            $row = array_map(fn ($v) => in_array($v['id'], $g['members'], true) ? 1. : 0., $variables);
            $matrix[] = $row;
            $rhs[] = $g['maximum'];
            $matrix[] = array_map(fn ($x) => -$x, $row);
            $rhs[] = -$g['minimum'];
        }
        if (str_starts_with($p['objective'], 'TARGET_')) {
            $t = (float) $p['target'];
            $matrix[] = array_map(fn ($s) => match ($p['objective']) {
                'TARGET_REVENUE' => -$s,'TARGET_CPA' => 1. - $t * $s,'TARGET_ROAS' => $t - $s
            }, $slopes);
            $rhs[] = $p['objective'] === 'TARGET_REVENUE' ? -$t : 0.;
        }
        foreach ($r['trace'] as $step) {
            $q = $step['ratio_parameter'] ?? null;
            if (! is_numeric($q) || ! is_finite((float) $q)) {
                return ['status' => 'invalid_ratio_certificate'];
            }
            $cost = array_map(fn ($s) => (($p['target_optimization'] ?? null) === 'minimize_spend') ? 1. : -($s - $q), $slopes);
            $y = $step['dual_inequality'] ?? [];
            $lo = $step['dual_lower'] ?? [];
            $hi = $step['dual_upper'] ?? [];
            if (count($y) !== count($rhs) || count($lo) !== $n || count($hi) !== $n) {
                return ['status' => 'dual_population_mismatch'];
            }
            foreach (array_merge($y, $lo, $hi) as $d) {
                if (! is_numeric($d) || ! is_finite((float) $d)) {
                    return ['status' => 'nonfinite_dual'];
                }
            }
            $dual = 0.;
            foreach ($y as $i => $d) {
                if ($d > 1e-9) {
                    return ['status' => 'dual_sign_rejected'];
                }$dual += $rhs[$i] * $d;
            }
            foreach ($variables as $j => $v) {
                if ($lo[$j] < -1e-9 || $hi[$j] > 1e-9) {
                    return ['status' => 'bound_dual_sign_rejected'];
                }
                $stationarity = $lo[$j] + $hi[$j];
                foreach ($matrix as $i => $row) {
                    $stationarity += $row[$j] * $y[$i];
                }
                if (abs($cost[$j] - $stationarity) > 1e-7 * max(1., abs($cost[$j]))) {
                    return ['status' => 'dual_stationarity_rejected'];
                }
                $dual += $v['minimum'] * $lo[$j] + $v['maximum'] * $hi[$j];
            }
            if (! is_numeric($step['primal_objective'] ?? null) || ! is_finite((float) $step['primal_objective']) || abs($dual - $step['primal_objective']) > 1e-7 * max(1., abs($dual))) {
                return ['status' => 'primal_dual_gap_rejected'];
            }
        }
        $last = $r['trace'][count($r['trace']) - 1];
        $spend = (float) $check['spend_exact'];
        $response = $check['response_approximate'];
        $primal = (($p['target_optimization'] ?? null) === 'minimize_spend') ? $spend : -($response - $last['ratio_parameter'] * $spend);
        if (abs($primal - $last['primal_objective']) > 1e-7 * max(1., abs($primal))) {
            return ['status' => 'independent_objective_mismatch'];
        }
        if (in_array($p['objective'], ['MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true) && abs($response - $last['ratio_parameter'] * $spend) > 1e-8 * max(1., $response)) {
            return ['status' => 'fractional_certificate_not_closed'];
        }
        if (in_array($p['objective'], ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS'], true) && ! isset($p['risk']) && BigDecimal::of($p['minimum_response'])->isZero()) {
            $bound = (new LinearDualResidualBound)->maximum($problem, $slopes, $last);
            if ($bound['status'] !== 'bounded') {
                return ['status' => 'represented_linear_bound_unavailable', 'bound' => $bound];
            }
            $responseExact = BigRational::zero();
            foreach ($p['variables'] as $i => $v) {
                $responseExact = $responseExact->plus(Binary64Boundary::rational($slopes[$i])->multipliedBy($allocation[$v['id']]));
            }
            $upper = BigRational::of($bound['upper_exact']['numerator'])->dividedBy($bound['upper_exact']['denominator']);
            $gap = $upper->minus($responseExact);
            $tolerance = ($responseExact->isGreaterThan(1) ? $responseExact : BigRational::one())->multipliedBy('0.0000001');
            if ($gap->isLessThan(0) || $gap->isGreaterThan($tolerance)) {
                return ['status' => 'represented_linear_bound_gap_rejected', 'bound' => $bound];
            }
            $r['represented_linear_optimality_bound'] = $bound + ['candidate_response_exact' => ExactValue::fraction($responseExact),
                'gap_exact' => ExactValue::fraction($gap), 'acceptance_gap_tolerance_exact' => ExactValue::fraction($tolerance),
                'claim' => 'Rational bound for represented binary64 slopes over the original decimal feasible box/budget/groups, not exact real-curve truth, calibration or causal effect.'];
        } else {
            $r['represented_linear_optimality_bound'] = ['status' => 'unavailable', 'reason' => 'only_nonrobust_linear_maxima_with_zero_response_floor_supported'];
        }
        $r['exact_vertex_recovery'] = $recovery;
        $r['exact_represented_linear_check'] = $representedCheck;
        $r['solver_script_sha256'] = hash_file('sha256', base_path('solver/typed_linear_lp.py'));

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => $r];
    }
}
