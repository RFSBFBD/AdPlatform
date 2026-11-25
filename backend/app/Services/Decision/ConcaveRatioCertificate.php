<?php

declare(strict_types=1);

namespace App\Services\Decision;

use Symfony\Component\Process\Process;

/** Upper bound on F(x)-q*spend over a relaxed domain certifies modeled response/spend. */
final class ConcaveRatioCertificate
{
    public function certify(DecisionProblem $problem, array $allocation, array $check, array $proposal, array $recovery, array $tangents, float $intercept): array
    {
        $p = $problem->jsonSerialize();
        $spend = (float) $check['spend_exact'];
        $response = $check['response_approximate'];
        if ($spend <= 0 || $response <= 0) {
            return ['status' => 'undefined_concave_ratio'];
        }
        $q = $response / $spend;
        $variables = [];
        foreach ($p['variables'] as $v) {
            $variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum'], 'slope' => $tangents[$v['id']]['slope_approximate']];
        }
        $groups = array_map(fn ($g) => ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']], $p['groups']);
        $input = ['variables' => $variables, 'groups' => $groups, 'objective' => 'MAXIMIZE_CONVERSIONS', 'target' => null, 'target_optimization' => null, 'budget_cap' => (float) $p['budget_cap'], 'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => 0., 'fixed_ratio_parameter' => $q];
        try {
            $process = new Process([config('solver.python'), base_path('solver/typed_linear_lp.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $lp = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'ratio_tangent_lp_failed'];
        }
        if (($lp['status'] ?? null) !== 'solved' || ! is_array($lp['trace'] ?? null) || $lp['trace'] === []) {
            return ['status' => 'ratio_tangent_lp_not_solved'];
        }
        $spec = $p;
        unset($spec['problem_version']);
        $spec['allocation_mode'] = 'continuous_linear';
        $spec['objective'] = 'MAXIMIZE_CONVERSIONS';
        $spec['response_metric'] = 'conversions';
        $spec['minimum_response'] = '0';
        $slopes = array_map(fn ($t) => $t['slope_approximate'] - $q, array_values($tangents));
        $last = $lp['trace'][count($lp['trace']) - 1];
        $bound = (new LinearDualResidualBound)->maximum(new DecisionProblem($spec), $slopes, $last);
        if ($bound['status'] !== 'bounded') {
            return ['status' => 'ratio_linear_bound_unavailable'];
        }
        $residualUpper = $intercept + $bound['upper_approximate'];
        $tolerance = 1e-6 * max(1., $response);
        if (! is_finite($residualUpper) || $residualUpper < -1e-7 * max(1., $response) || $residualUpper > $tolerance) {
            return ['status' => 'ratio_tangent_gap_not_closed', 'residual_upper_approximate' => $residualUpper];
        }
        $ratioUpper = $q + max(0., $residualUpper) / (float) $p['minimum_spend'];

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => [
            'version' => 'typed-concave-ratio-v1', 'solver' => 'SLSQP_Dinkelbach_proposal_plus_independent_tangent_LP_bound', 'proposal' => $proposal, 'exact_candidate_recovery' => $recovery,
            'tangents' => $tangents, 'tangent_intercept_approximate' => $intercept, 'tangent_lp' => $lp, 'represented_linear_residual_bound' => $bound,
            'candidate_response_per_spend_approximate' => $q, 'ratio_upper_approximate' => $ratioUpper, 'cpa_lower_approximate' => 1 / $ratioUpper, 'fractional_residual_upper_approximate' => $residualUpper,
            'minimum_spend_denominator_exact' => $p['minimum_spend'], 'guarantee' => 'numerical_tangent_fractional_gap_for_declared_separable_concave_model_not_exact_real_or_future_guarantee',
            'limits' => 'Response-floor constraint relaxed only for upper bound; original candidate checked. Binary64 nonlinear tangents and subtraction are not interval-certified. CPA is reciprocal response/spend with explicit positive floors.',
            'money_constraint_tolerance' => '0', 'solver_script_sha256' => hash_file('sha256', base_path('solver/typed_concave_proposal.py'))]];
    }
}
