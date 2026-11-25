<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use Symfony\Component\Process\Process;

/** Necessary tangent constraints bound the declared response or spend objective with a CPA/ROAS target. */
final class ConcaveRatioTargetCertificate
{
    public function certify(DecisionProblem $problem, array $allocation, array $check, array $proposal, array $recovery, array $tangents, float $intercept): array
    {
        $p = $problem->jsonSerialize();
        $target = (float) $p['target'];
        $minimize = $p['target_optimization'] === 'minimize_spend';
        $variables = [];
        foreach ($p['variables'] as $v) {
            $variables[] = ['id' => $v['id'], 'minimum' => (float) $v['minimum'], 'maximum' => (float) $v['maximum'], 'slope' => $tangents[$v['id']]['slope_approximate']];
        }
        $groups = array_map(fn ($g) => ['id' => $g['id'], 'members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']], $p['groups']);
        $input = ['variables' => $variables, 'groups' => $groups, 'objective' => $p['objective'], 'target' => $target, 'target_optimization' => $p['target_optimization'], 'response_intercept' => $intercept, 'budget_cap' => (float) $p['budget_cap'], 'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => 0.];
        $slopes = array_column(array_values($tangents), 'slope_approximate');
        // Keep original positive response floor as a necessary tangent row for minimum-spend bounds.
        if ($minimize) {
            $input['extra_inequalities'] = [['row' => array_map(fn ($m) => -$m, $slopes), 'rhs' => $intercept - (float) $p['minimum_response']]];
        }
        try {
            $process = new Process([config('solver.python'), base_path('solver/typed_linear_lp.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $lp = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'ratio_target_tangent_lp_failed'];
        }
        if (($lp['status'] ?? null) !== 'solved' || ! is_array($lp['trace'] ?? null) || $lp['trace'] === []) {
            return ['status' => 'ratio_target_tangent_lp_not_solved'];
        }
        foreach ($slopes as $m) {
            if (! is_finite($target * $m) || ! is_finite($target * $intercept)) {
                return ['status' => 'ratio_target_tangent_numerical_range'];
            }
        }
        $row = array_map(fn ($m) => Binary64Boundary::rational($p['objective'] === 'TARGET_CPA' ? 1. - $target * $m : $target - $m), $slopes);
        $rhs = Binary64Boundary::rational($p['objective'] === 'TARGET_CPA' ? $target * $intercept : $intercept);
        $spec = $p;
        unset($spec['problem_version'],$spec['target'],$spec['target_optimization']);
        $spec['allocation_mode'] = 'continuous_linear';
        $spec['objective'] = 'MAXIMIZE_CONVERSIONS';
        $spec['response_metric'] = 'conversions';
        $spec['minimum_response'] = '0';
        $rows = [$row];
        $bounds = [$rhs];
        if ($minimize) {
            $rows[] = array_map(fn ($m) => Binary64Boundary::rational(-$m), $slopes);
            $bounds[] = Binary64Boundary::rational($intercept - (float) $p['minimum_response']);
        }
        $bound = (new LinearDualResidualBound)->maximum(new DecisionProblem($spec), $minimize ? array_fill(0, count($slopes), -1.) : $slopes, $lp['trace'][count($lp['trace']) - 1], $rows, $bounds);
        if ($bound['status'] !== 'bounded') {
            return ['status' => 'ratio_target_bound_unavailable'];
        }
        $upper = $minimize ? -$bound['upper_approximate'] : $intercept + $bound['upper_approximate'];
        $value = $minimize ? (float) $check['spend_exact'] : $check['response_approximate'];
        $gap = $minimize ? $value - $upper : $upper - $value;
        if (! is_finite($gap) || $gap < -1e-7 * max(1., $value) || $gap > 1e-6 * max(1., $value)) {
            return ['status' => 'ratio_target_gap_not_closed', 'gap_approximate' => $gap];
        }

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'independent_check' => $check, 'certificate' => [
            'version' => 'typed-concave-ratio-target-v1', 'solver' => 'SLSQP_superlevel_target_plus_necessary_tangent_LP', 'proposal' => $proposal, 'exact_candidate_recovery' => $recovery,
            'tangents' => $tangents, 'intercept_approximate' => $intercept, 'tangent_lp' => $lp, 'represented_linear_bound' => $bound, 'optimization_policy' => $p['target_optimization'], 'bound_direction' => $minimize ? 'lower' : 'upper', 'objective_bound_approximate' => $upper, 'candidate_objective_approximate' => $value, 'gap_approximate' => $gap,
            'guarantee' => 'numerical_declared_secondary_objective_gap_under_concave_ratio_target_not_exact_real_or_future_guarantee',
            'limits' => 'Original candidate targets strictly checked. Tangent necessary target constraint and nonlinear intercept use binary64, not certified intervals. Response floor relaxed only for maximum-response upper bound; necessary tangent floor retained for minimum-spend lower bound.',
            'solver_script_sha256' => hash_file('sha256', base_path('solver/typed_concave_proposal.py'))]];
    }
}
