<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\Accounting\Binary64Boundary;
use Symfony\Component\Process\Process;

/** Smooth separable concave maxima. Proposal accepted only with exact money and independent tangent LP evidence. */
final class TypedConcaveSolver
{
    public function solve(DecisionProblem $problem, array $models): array
    {
        $p = $problem->jsonSerialize();
        if (! (in_array($p['objective'], ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS', 'MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true) || $p['objective'] === 'TARGET_REVENUE' || in_array($p['objective'], ['TARGET_CPA', 'TARGET_ROAS'], true))) {
            return ['status' => 'unsupported_concave_objective_or_response_constraint'];
        }
        $python = config('solver.python');
        if (! is_string($python) || ! str_starts_with($python, '/') || ! is_executable($python)) {
            return ['status' => 'solver_unavailable'];
        }
        $variables = [];
        foreach ($p['variables'] as $v) {
            $curve = $models[$v['id']]['curve'];
            $name = $curve->name();
            if (! $curve instanceof ResponseCurve || ! in_array($name, ['linear', 'log', 'saturation', 'power', 'quadratic_spline'], true) || $name === 'power' && (float) $v['minimum'] < 1e-9 || $name === 'quadratic_spline' && $curve->parameters()['b'] <= 0) {
                return ['status' => 'unsupported_concave_shape_or_power_derivative_boundary'];
            }
            foreach (['minimum', 'maximum'] as $key) {
                $x = (float) $v[$key];
                if (! is_finite($x) || $x > 1e12 || Binary64Boundary::loss((string) $v[$key], $x)->isGreaterThan('0.0001')) {
                    return ['status' => 'bound_conversion_loss'];
                }
            }
            if ((float) $v['minimum'] < (method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0.) || (float) $v['maximum'] > $curve->supportMaxSpend()) {
                return ['status' => 'bounds_outside_model_support'];
            }
            $variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum'], 'name' => $name, 'parameters' => $curve->parameters(),
                'scale' => isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.];
        }
        $groups = [];
        foreach ($p['groups'] as $g) {
            foreach (['minimum', 'maximum'] as $key) {
                $x = (float) $g[$key];
                if (! is_finite($x) || $x > 1e12 || Binary64Boundary::loss((string) $g[$key], $x)->isGreaterThan('0.0001')) {
                    return ['status' => 'group_bound_conversion_loss'];
                }
            }
            $groups[] = ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']];
        }
        $ratioTarget = 0.;
        if (in_array($p['objective'], ['TARGET_CPA', 'TARGET_ROAS'], true)) {
            $target = (float) $p['target'];
            if ($target <= 0.) {
                return ['status' => 'ratio_target_numerical_range'];
            }
            $ratioTarget = $p['objective'] === 'TARGET_CPA' ? 1. / $target : $target;
            if (! is_finite($ratioTarget) || $ratioTarget <= 0.) {
                return ['status' => 'ratio_target_numerical_range'];
            }
        }
        $input = ['ratio_target' => $ratioTarget, 'objective' => $p['objective'], 'variables' => $variables, 'groups' => $groups, 'budget_cap' => (float) $p['budget_cap'], 'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => (float) $p['minimum_response'], 'target_optimization' => $p['target_optimization'] ?? null, 'target_response' => $p['objective'] === 'TARGET_REVENUE' ? (float) $p['target'] : 0.];
        try {
            $process = new Process([$python, base_path('solver/typed_concave_proposal.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $proposal = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'solver_failed_or_timeout'];
        }
        if (($proposal['status'] ?? null) !== 'proposed') {
            return $proposal;
        }
        if (($proposal['version'] ?? null) !== 'typed-concave-proposal-v1' || ($proposal['solver'] ?? null) !== 'scipy.optimize.minimize/SLSQP' || ! is_array($proposal['allocation'] ?? null)) {
            return ['status' => 'invalid_concave_proposal'];
        }
        $allocation = [];
        foreach ($p['variables'] as $v) {
            $x = $proposal['allocation'][$v['id']] ?? null;
            if ((! is_int($x) && ! is_float($x)) || ! is_finite((float) $x) || $x < 0) {
                return ['status' => 'invalid_concave_allocation'];
            }$allocation[$v['id']] = (string) Binary64Boundary::rational((float) $x)->toBigDecimal();
        }
        if (array_diff(array_keys($proposal['allocation']), array_keys($allocation))) {
            return ['status' => 'proposal_population_mismatch'];
        }
        $recovery = (new ExactLinearVertexRecovery)->recover($problem, $proposal['allocation'], array_fill(0, count($variables), 0.), true);
        if ($recovery['status'] === 'recovered_candidate') {
            $allocation = $recovery['allocation'];
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation, false);
        if ($check['status'] !== 'checked') {
            return ['status' => 'independent_concave_feasibility_rejected', 'check' => $check, 'recovery' => $recovery];
        }
        // The tangent inequality holds globally only for these admitted monotone concave families on their support.
        $tangentModels = [];
        $tangents = [];
        $intercept = 0.;
        foreach ($p['variables'] as $v) {
            $id = $v['id'];
            $curve = $models[$id]['curve'];
            $x = (float) $allocation[$id];
            $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$id]['lower_scale'] : 1.;
            $slope = $curve->marginalAt($x) * $scale;
            $value = $curve->revenueAt($x) * $scale;
            if (! is_finite($slope) || $slope <= 0 || ! is_finite($value)) {
                return ['status' => 'unsupported_zero_or_nonfinite_tangent'];
            }
            $intercept += $value - $slope * $x;
            $tangentModels[$id] = ['curve' => ResponseCurve::linear($slope, $curve->supportMaxSpend(), method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0.)];
            $tangents[$id] = ['at_spend_exact' => $allocation[$id], 'response_approximate' => $value, 'slope_approximate' => $slope];
        }
        $linearSpec = $p;
        unset($linearSpec['problem_version'],$linearSpec['risk'],$linearSpec['budget_shock_multipliers'],$linearSpec['forecast_horizon_days'],$linearSpec['primitive_shocks']);
        $linearSpec['allocation_mode'] = 'continuous_linear';
        if (in_array($p['objective'], ['TARGET_CPA', 'TARGET_ROAS'], true)) {
            return (new ConcaveRatioTargetCertificate)->certify($problem, $allocation, $check, $proposal, $recovery, $tangents, $intercept);
        }
        if (in_array($p['objective'], ['MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true)) {
            return (new ConcaveRatioCertificate)->certify($problem, $allocation, $check, $proposal, $recovery, $tangents, $intercept);
        }
        if (($p['target_optimization'] ?? null) === 'minimize_spend') {
            return (new ConcaveTargetSpendCertificate)->certify($problem, $allocation, $check, $proposal, $recovery, $tangentModels, $tangents, $intercept);
        }
        // Relax response/target constraints for a valid upper bound on the smaller feasible set.
        $linearSpec['objective'] = 'MAXIMIZE_CONVERSIONS';
        $linearSpec['response_metric'] = 'conversions';
        $linearSpec['minimum_response'] = '0';
        unset($linearSpec['target'], $linearSpec['target_optimization']);
        $upper = (new TypedLinearLpSolver)->solve(new DecisionProblem($linearSpec), $tangentModels);
        if ($upper['status'] !== 'checked_numerical_solver') {
            return ['status' => 'tangent_lp_not_accepted', 'tangent_result' => $upper];
        }
        $tangentSlopes = array_map(fn ($t) => $t['slope_approximate'], array_values($tangents));
        $trace = $upper['certificate']['trace'];
        $residualBound = (new LinearDualResidualBound)->maximum(new DecisionProblem($linearSpec), $tangentSlopes, $trace[count($trace) - 1]);
        if ($residualBound['status'] !== 'bounded') {
            return ['status' => 'tangent_residual_bound_unavailable'];
        }
        $bound = $intercept + $residualBound['upper_approximate'];
        $value = $check['response_approximate'];
        $gap = $bound - $value;
        if (! is_finite($bound) || $gap < -1e-7 * max(1., abs($value)) || $gap > 1e-6 * max(1., abs($value))) {
            return ['status' => 'tangent_gap_not_closed', 'upper_bound_approximate' => $bound, 'candidate_response_approximate' => $value, 'gap_approximate' => $gap];
        }

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => [
            'solver' => 'SLSQP_proposal_plus_independent_HiGHS_tangent_LP', 'version' => 'typed-concave-tangent-v1', 'proposal' => $proposal, 'exact_candidate_recovery' => $recovery,
            'bound_relaxation' => 'nonlinear_response_floor_and_revenue_target_dropped_only_in_upper_bound_LP_original_constraints_checked_on_candidate', 'tangents' => $tangents, 'tangent_lp' => $upper['certificate'], 'tangent_dual_residual_bound' => $residualBound, 'upper_bound_approximate' => $bound, 'response_approximate' => $value, 'gap_approximate' => $gap,
            'transcendental_evaluation' => 'binary64_not_interval_certified', 'tangent_bound_semantics' => 'exact_residual_corrected_represented_linear_envelope_plus_numerical_nonlinear_intercept', 'gap_relative_admission_limit' => '0.000001', 'money_constraint_tolerance' => '0', 'guarantee' => 'numerical_supporting_tangent_bound_for_declared_separable_concave_models_not_exact_real_or_future_guarantee',
            'solver_script_sha256' => hash_file('sha256', base_path('solver/typed_concave_proposal.py'))]];
    }
}
