<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;

/** Separately recomputes represented feasibility and modeled objective. No budget epsilon. */
final class IndependentAllocationCheck
{
    public function check(DecisionProblem $problem, array $models, array $allocation, bool $requireMenu = true): array
    {
        $p = $problem->jsonSerialize();
        $reasons = [];
        $bestResponse = 0.0;
        $nominalResponse = 0.0;
        $spend = BigDecimal::zero();
        $response = 0.0;
        $parts = [];
        $ids = array_column($p['variables'], 'id');
        if (array_diff(array_keys($allocation), $ids) || array_diff($ids, array_keys($allocation))) {
            return ['status' => 'rejected', 'reasons' => ['allocation_population_mismatch']];
        }
        foreach ($p['variables'] as $v) {
            $x = ExactValue::of($allocation[$v['id']])->decimal();
            if ($x->isLessThan($v['minimum']) || $x->isGreaterThan($v['maximum'])) {
                $reasons[] = 'variable_bound:'.$v['id'];
            }
            $menuHit = false;
            foreach ($v['menu'] as $menu) {
                if ($x->isEqualTo($menu)) {
                    $menuHit = true;
                    break;
                }
            }
            if ($p['allocation_mode'] === 'finite_menu' && $requireMenu && ! $menuHit) {
                $reasons[] = 'allocation_not_in_menu:'.$v['id'];
            }
            $spend = $spend->plus($x);
            $approximate = (float) (string) $x;
            // Bound conversion and magnitude; exact money constraints do not use this float.
            if (! is_finite($approximate) || abs($approximate) > 1e12) {
                $reasons[] = 'model_numeric_range';

                continue;
            }
            $conversionError = Binary64Boundary::loss((string) $x, $approximate);
            if ($conversionError->isGreaterThan('0.0001')) {
                $reasons[] = 'model_operand_conversion_loss';

                continue;
            }
            $curve = $models[$v['id']]['curve'];
            if ($approximate < (method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0.0) || $approximate > $curve->supportMaxSpend()) {
                $reasons[] = 'outside_validated_model_support:'.$v['id'];
            }
            $nominal = $curve->revenueAt($approximate);
            $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.0;
            $y = $nominal * $scale;
            if (! is_finite($y) || $y < 0) {
                $reasons[] = 'invalid_modeled_response';

                continue;
            }
            $parts[$v['id']] = ['spend_exact' => (string) $x, 'response_approximate' => $y, 'nominal_response_approximate' => $nominal, 'risk_lower_scale' => $scale,
                'operand_absolute_conversion_error_exact' => ExactValue::fraction($conversionError)];
            $response += $y;
            $nominalResponse += $nominal;
            $upper = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['upper_scale'] : 1.0;
            $bestResponse += $nominal * $upper;
        }
        if ($spend->isGreaterThan($p['budget_cap']) || $spend->isLessThan($p['minimum_spend'])) {
            $reasons[] = 'exact_budget_bound';
        }
        foreach ($p['groups'] as $g) {
            $sum = BigDecimal::zero();
            foreach ($g['members'] as $id) {
                $sum = $sum->plus($allocation[$id]);
            }
            if ($sum->isLessThan($g['minimum']) || $sum->isGreaterThan($g['maximum'])) {
                $reasons[] = 'exact_group_bound:'.$g['id'];
            }
        }
        if (! is_finite($bestResponse) || ! is_finite($nominalResponse)) {
            $reasons[] = 'nonfinite_stress_response';
        }
        $minimumResponse = (float) $p['minimum_response'];
        if (! is_finite($response) || ! is_finite($minimumResponse) || $response < $minimumResponse) {
            $reasons[] = 'modeled_minimum_response';
        }
        $s = (float) (string) $spend;
        $objective = match ($p['objective']) {
            'MINIMIZE_CPA', 'TARGET_CPA' => $response > 0 ? $s / $response : null,
            'MAXIMIZE_ROAS', 'TARGET_ROAS' => $s > 0 ? $response / $s : null,
            default => $response,
        };
        $target = isset($p['target']) ? (float) $p['target'] : null;
        if ($target !== null && (! is_finite($target) || match ($p['objective']) {
            'TARGET_REVENUE' => $response < $target,
            'TARGET_CPA' => $objective === null || $objective > $target,
            'TARGET_ROAS' => $objective === null || $objective < $target,
            default => false,
        })) {
            $reasons[] = 'modeled_target_not_met';
        }
        if ($objective === null || ! is_finite($objective)) {
            $reasons[] = 'undefined_modeled_objective';
        }

        $representedLinear = null;
        if (count($parts) === count($p['variables']) && count(array_filter($p['variables'], fn ($v) => $models[$v['id']]['curve']->name() === 'linear')) === count($p['variables'])) {
            $representedLinear = (new ExactLinearModelCheck)->check($problem, $models, $allocation);
            if ($representedLinear['status'] !== 'checked') {
                $reasons[] = 'represented_linear_feasibility_rejected';
            }
        }

        $representedAlgebraic = null;
        if (count($parts) === count($p['variables']) && $representedLinear === null) {
            $representedAlgebraic = (new ExactAlgebraicModelCheck)->check($problem, $models, $allocation);
            if ($representedAlgebraic['status'] === 'rejected') {
                $reasons[] = 'represented_algebraic_feasibility_rejected';
            }
        }

        $quadratic = null;
        if (isset($p['quadratic_risk']) && count($parts) === count($p['variables'])) {
            $slopes = array_map(fn ($v) => Binary64Boundary::rational($models[$v['id']]['curve']->parameters()['a']), $p['variables']);
            $q = (new QuadraticRiskPolicy)->evaluate($p, $allocation, $slopes);
            $objective = $q['objective']->toFloat();
            if (! is_finite($objective)) {
                $reasons[] = 'quadratic_objective_nonfinite';
            }
            $quadratic = ['mode' => $p['quadratic_risk']['mode'], 'lambda' => $p['quadratic_risk']['lambda'], 'expected_response_exact' => ExactValue::fraction($q['expected']), 'declared_variance_approximate' => $q['variance']->toFloat(), 'penalty_approximate' => $q['penalty']->toFloat(), 'adjusted_objective_approximate' => $objective, 'declared_variance_exact' => ExactValue::fraction($q['variance']), 'penalty_exact' => ExactValue::fraction($q['penalty']), 'adjusted_objective_exact' => ExactValue::fraction($q['objective']), 'calibration' => 'not_established', 'probability' => null];
        }

        return ['status' => $reasons ? 'rejected' : 'checked', 'reasons' => array_values(array_unique($reasons)),
            'spend_exact' => (string) $spend, 'response_approximate' => $response, 'objective_approximate' => $objective,
            'quadratic_risk_evaluation' => $quadratic, 'optimization_measure' => ['target_optimization' => $p['target_optimization'] ?? null,
                'direction' => (($p['target_optimization'] ?? null) === 'minimize_spend' || $p['objective'] === 'MINIMIZE_CPA') ? 'minimize' : 'maximize',
                'metric' => (($p['target_optimization'] ?? null) === 'minimize_spend') ? 'spend' : (str_starts_with($p['objective'], 'TARGET_') ? 'response' : 'objective'),
                'value_exact' => (($p['target_optimization'] ?? null) === 'minimize_spend') ? (string) $spend : null,
                'value_approximate' => (($p['target_optimization'] ?? null) === 'minimize_spend') ? null : (str_starts_with($p['objective'], 'TARGET_') ? $response : $objective)],
            'represented_algebraic_check' => $representedAlgebraic, 'represented_linear_check' => $representedLinear, 'parts' => $parts, 'money_constraint_tolerance' => '0', 'model_arithmetic' => 'binary64_approximate',
            'stress' => ['lower_assumption_response_approximate' => $response, 'nominal_response_approximate' => $nominalResponse,
                'upper_assumption_response_approximate' => $bestResponse, 'kind' => isset($p['risk']) ? 'independent_response_scale_box' : 'no_uncertainty_set_supplied',
                'probability' => null, 'interval_kind' => 'deterministic_assumption_range_not_prediction_interval'],
            'model_operand_maximum_absolute_loss' => '0.0001', 'rounding_policy' => 'none'];
    }
}
