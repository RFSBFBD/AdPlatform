<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use Symfony\Component\Process\Process;

/** Optional deployment-owned solver, with no requested constraints dropped. */
final class TypedFiniteMilpSolver
{
    public function solve(DecisionProblem $problem, array $models): array
    {
        $python = config('solver.python');
        if (! is_string($python) || ! str_starts_with($python, '/') || ! is_executable($python)) {
            return ['status' => 'solver_unavailable'];
        }
        $p = $problem->jsonSerialize();
        $menus = [];
        foreach ($p['variables'] as $v) {
            $choices = [];
            foreach ($v['menu'] as $point) {
                $x = (float) $point;
                if (! is_finite($x) || $x > 1e12 || Binary64Boundary::loss((string) $point, $x)->isGreaterThan('0.0001')) {
                    return ['status' => 'solver_operand_conversion_loss'];
                }
                $curve = $models[$v['id']]['curve'];
                if ($x < (method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0) || $x > $curve->supportMaxSpend()) {
                    return ['status' => 'solver_menu_outside_model_support'];
                }
                $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.0;
                $choices[] = ['spend' => $x, 'response' => $scale * $curve->revenueAt($x)];
            }
            $menus[] = ['id' => $v['id'], 'choices' => $choices];
        }
        $groups = [];
        foreach ($p['groups'] as $g) {
            foreach (['minimum', 'maximum'] as $key) {
                $f = (float) $g[$key];
                if (! is_finite($f) || $f > 1e12 || Binary64Boundary::loss((string) $g[$key], $f)->isGreaterThan('0.0001')) {
                    return ['status' => 'group_bound_numeric_range_or_conversion_loss'];
                }
            }
            $groups[] = ['members' => $g['members'], 'minimum' => (float) $g['minimum'], 'maximum' => (float) $g['maximum']];
        }
        $input = ['menus' => $menus, 'groups' => $groups, 'objective' => $p['objective'], 'target_optimization' => $p['target_optimization'] ?? null, 'budget_cap' => (float) $p['budget_cap'],
            'minimum_spend' => (float) $p['minimum_spend'], 'minimum_response' => (float) $p['minimum_response'], 'target' => isset($p['target']) ? (float) $p['target'] : null];
        try {
            $process = new Process([$python, base_path('solver/typed_finite_milp.py')]);
            $process->setTimeout(20);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->run();
            if (! $process->isSuccessful()) {
                return ['status' => 'solver_failed'];
            }
            $r = json_decode($process->getOutput(), true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'solver_failed_or_timeout'];
        }
        if (($r['status'] ?? '') !== 'solved') {
            return $r;
        }
        if (($r['solver'] ?? '') !== 'scipy.optimize.milp/HiGHS' || ($r['version'] ?? '') !== 'typed-finite-milp-v1'
            || ! is_string($r['scipy_version'] ?? null) || ! preg_match('/^\d+\.\d+\.\d+/D', $r['scipy_version'])
            || ! is_array($r['choices'] ?? null) || ! is_array($r['trace'] ?? null) || $r['trace'] === []) {
            return ['status' => 'invalid_solver_certificate'];
        }
        foreach ($r['trace'] as $step) {
            foreach (['mip_gap', 'spend', 'response', 'ratio_parameter', 'fractional_residual', 'linear_dual_bound'] as $key) {
                if (! isset($step[$key]) || (! is_float($step[$key]) && ! is_int($step[$key])) || ! is_finite((float) $step[$key])) {
                    return ['status' => 'invalid_solver_certificate'];
                }
            }
            if ($step['mip_gap'] < 0 || $step['mip_gap'] > 1e-8) {
                return ['status' => 'solver_gap_not_closed'];
            }
        }
        $allocation = [];
        foreach ($p['variables'] as $v) {
            $index = $r['choices'][$v['id']] ?? null;
            if (! is_int($index) || ! array_key_exists($index, $v['menu'])) {
                return ['status' => 'invalid_solver_choice'];
            }$allocation[$v['id']] = $v['menu'][$index];
        }
        if (array_diff(array_keys($r['choices']), array_keys($allocation))) {
            return ['status' => 'invalid_solver_population'];
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation);
        if ($check['status'] !== 'checked') {
            return ['status' => 'independent_check_failed', 'check' => $check];
        }
        $last = $r['trace'][count($r['trace']) - 1];
        $response = $check['response_approximate'];
        $spend = (float) $check['spend_exact'];
        if (abs($response - $last['response']) > 1e-8 * max(1, $response) || abs($spend - $last['spend']) > 1e-8 * max(1, $spend)) {
            return ['status' => 'independent_solver_objective_mismatch'];
        }
        $linearObjective = (($p['target_optimization'] ?? null) === 'minimize_spend') ? $spend : -($response - $last['ratio_parameter'] * $spend);
        if (abs($linearObjective - $last['linear_dual_bound']) > 1e-7 * max(1, abs($linearObjective))) {
            return ['status' => 'independent_primal_dual_mismatch'];
        }
        if (in_array($p['objective'], ['MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true) && abs($last['fractional_residual']) > 1e-8 * max(1, $response)) {
            return ['status' => 'fractional_certificate_not_closed'];
        }

        $r['solver_script_sha256'] = hash_file('sha256', base_path('solver/typed_finite_milp.py'));

        return ['status' => 'checked_numerical_solver', 'allocation' => $allocation, 'certificate' => $r, 'independent_check' => $check];
    }
}
