<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;
use Symfony\Component\Process\Process;

/** Necessary tangent superlevel LP supplies a numerical lower bound for minimum target spend. */
final class ConcaveTargetSpendCertificate
{
    public function certify(DecisionProblem $problem, array $allocation, array $check, array $proposal, array $recovery, array $models, array $tangents, float $intercept): array
    {
        $p = $problem->jsonSerialize();
        $floor = max((float) $p['target'], (float) $p['minimum_response']);
        $effective = $floor - $intercept;
        if (! is_finite($effective) || $effective <= 0) {
            return ['status' => 'unsupported_nonpositive_tangent_target_relaxation'];
        }
        $spec = $p;
        unset($spec['problem_version'], $spec['risk'], $spec['budget_shock_multipliers'], $spec['forecast_horizon_days'], $spec['primitive_shocks']);
        $spec['allocation_mode'] = 'continuous_linear';
        $spec['minimum_response'] = '0';
        $spec['target'] = (string) Binary64Boundary::rational($effective)->toBigDecimal();
        // A lower-bound dual does not require a feasible LP primal allocation or terminating vertex.
        $variables = [];
        foreach ($p['variables'] as $v) {
            $variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum'], 'slope' => $tangents[$v['id']]['slope_approximate']];
        }
        $groups = array_map(fn ($g) => ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']], $p['groups']);
        $input = ['variables' => $variables, 'groups' => $groups, 'objective' => 'TARGET_REVENUE', 'target_optimization' => 'minimize_spend', 'budget_cap' => (float) $p['budget_cap'], 'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => 0., 'target' => $effective];
        try {
            $process = new Process([config('solver.python'), base_path('solver/typed_linear_lp.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $lp = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'target_tangent_lp_proposal_failed'];
        }
        if (($lp['status'] ?? null) !== 'solved' || ! is_array($lp['trace'] ?? null) || $lp['trace'] === []) {
            return ['status' => 'target_tangent_lp_not_solved'];
        }
        $last = $lp['trace'][count($lp['trace']) - 1];
        if (count($last['dual_inequality'] ?? []) !== 4 + 2 * count($p['groups']) || count($last['dual_lower'] ?? []) !== count($p['variables']) || count($last['dual_upper'] ?? []) !== count($p['variables'])) {
            return ['status' => 'target_tangent_dual_dimensions'];
        }
        foreach (array_merge($last['dual_inequality'], $last['dual_lower'], $last['dual_upper']) as $d) {
            if (! is_numeric($d) || ! is_finite((float) $d)) {
                return ['status' => 'target_tangent_dual_nonfinite'];
            }
        }
        $n = count($p['variables']);
        $one = BigRational::one();
        $zero = BigRational::zero();
        $matrix = [array_fill(0, $n, $one), array_fill(0, $n, $one->negated()), array_fill(0, $n, $zero)];
        $rhs = [BigRational::of($p['budget_cap']), BigRational::of($p['minimum_spend'])->negated(), $zero];
        foreach ($p['groups'] as $group) {
            $row = array_map(fn ($v) => in_array($v['id'], $group['members'], true) ? $one : $zero, $p['variables']);
            $matrix[] = $row;
            $rhs[] = BigRational::of($group['maximum']);
            $matrix[] = array_map(fn ($x) => $x->negated(), $row);
            $rhs[] = BigRational::of($group['minimum'])->negated();
        }
        $matrix[] = array_map(fn ($t) => Binary64Boundary::rational($t['slope_approximate'])->negated(), array_values($tangents));
        $rhs[] = BigRational::of($spec['target'])->negated();
        $y = array_map(fn ($d) => Binary64Boundary::rational(min(0., (float) $d)), $last['dual_inequality']);
        $lo = array_map(fn ($d) => Binary64Boundary::rational(max(0., (float) $d)), $last['dual_lower']);
        $hi = array_map(fn ($d) => Binary64Boundary::rational(min(0., (float) $d)), $last['dual_upper']);
        $lower = $zero;
        foreach ($y as $i => $d) {
            $lower = $lower->plus($rhs[$i]->multipliedBy($d));
        }
        foreach ($p['variables'] as $j => $v) {
            $lower = $lower->plus($lo[$j]->multipliedBy($v['minimum']))->plus($hi[$j]->multipliedBy($v['maximum']));
            $residual = $one->minus($lo[$j])->minus($hi[$j]);
            foreach ($matrix as $i => $row) {
                $residual = $residual->minus($row[$j]->multipliedBy($y[$i]));
            }
            $lower = $lower->plus($residual->multipliedBy($residual->isLessThan(0) ? $v['maximum'] : $v['minimum']));
        }
        $gap = BigRational::of($check['spend_exact'])->minus($lower)->toFloat();
        if (! is_finite($gap) || $gap < -1e-7 * max(1., (float) $check['spend_exact']) || $gap > 1e-6 * max(1., (float) $check['spend_exact'])) {
            return ['status' => 'target_spend_tangent_gap_not_closed', 'lower_bound_approximate' => $lower->toFloat(), 'gap_approximate' => $gap];
        }

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => [
            'version' => 'typed-concave-target-spend-v1', 'solver' => 'SLSQP_minimum_spend_proposal_plus_necessary_tangent_HiGHS_LP', 'proposal' => $proposal, 'exact_candidate_recovery' => $recovery,
            'tangents' => $tangents, 'intercept_approximate' => $intercept, 'effective_tangent_target_approximate' => $effective, 'tangent_lp' => $lp,
            'represented_linear_lower_exact' => ExactValue::fraction($lower), 'lower_bound_approximate' => $lower->toFloat(), 'candidate_spend_exact' => $check['spend_exact'], 'gap_approximate' => $gap,
            'guarantee' => 'numerical_minimum_spend_gap_for_declared_separable_concave_revenue_target_not_exact_nonlinear_or_future_guarantee',
            'bound_semantics' => 'rational_residual_corrected_linear_relaxation_with_binary64_nonlinear_tangent_intercept_not_interval_certified',
            'money_constraint_tolerance' => '0', 'solver_script_sha256' => hash_file('sha256', base_path('solver/typed_concave_proposal.py'))]];
    }
}
