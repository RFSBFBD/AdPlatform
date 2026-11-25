<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;

/** Exact rational feasibility for the represented linear model, not exact empirical coefficients. */
final class ExactLinearModelCheck
{
    public function check(DecisionProblem $problem, array $models, array $allocation): array
    {
        $p = $problem->jsonSerialize();
        $spend = BigRational::zero();
        $response = BigRational::zero();
        $parts = [];
        $reasons = [];
        foreach ($p['variables'] as $v) {
            $curve = $models[$v['id']]['curve'] ?? null;
            if ($curve === null || $curve->name() !== 'linear') {
                return ['status' => 'unavailable', 'reason' => 'linear_model_required'];
            }
            if (! array_key_exists($v['id'], $allocation)) {
                return ['status' => 'rejected', 'reasons' => ['allocation_population_mismatch']];
            }
            $x = ExactValue::of($allocation[$v['id']])->rational();
            $spend = $spend->plus($x);
            $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.;
            $slope = $curve->parameters()['a'] * $scale;
            if (! is_finite($slope) || $slope < 0) {
                return ['status' => 'rejected', 'reasons' => ['invalid_linear_slope']];
            }
            $s = Binary64Boundary::rational($slope);
            $y = $s->multipliedBy($x);
            $response = $response->plus($y);
            if ($x->isLessThan($v['minimum']) || $x->isGreaterThan($v['maximum'])) {
                $reasons[] = 'exact_variable_bound:'.$v['id'];
            }
            $parts[$v['id']] = ['spend' => ExactValue::fraction($x), 'represented_slope' => ExactValue::fraction($s), 'response' => ExactValue::fraction($y)];
        }
        if (array_diff(array_keys($allocation), array_keys($parts))) {
            $reasons[] = 'allocation_population_mismatch';
        }
        if ($spend->isGreaterThan($p['budget_cap']) || $spend->isLessThan($p['minimum_spend'])) {
            $reasons[] = 'exact_budget_bound';
        }
        foreach ($p['groups'] as $g) {
            $sum = BigRational::zero();
            foreach ($g['members'] as $id) {
                $sum = $sum->plus($allocation[$id]);
            }
            if ($sum->isLessThan($g['minimum']) || $sum->isGreaterThan($g['maximum'])) {
                $reasons[] = 'exact_group_bound:'.$g['id'];
            }
        }
        if ($response->isLessThan($p['minimum_response'])) {
            $reasons[] = 'represented_linear_minimum_response';
        }
        $objective = match ($p['objective']) {
            'MINIMIZE_CPA','TARGET_CPA' => $response->isZero() ? null : $spend->dividedBy($response),
            'MAXIMIZE_ROAS','TARGET_ROAS' => $spend->isZero() ? null : $response->dividedBy($spend),default => $response
        };
        if ($objective === null) {
            $reasons[] = 'undefined_linear_objective';
        }
        if (isset($p['target']) && match ($p['objective']) {
            'TARGET_REVENUE' => $response->isLessThan($p['target']),
            'TARGET_CPA' => $objective === null || $objective->isGreaterThan($p['target']),'TARGET_ROAS' => $objective === null || $objective->isLessThan($p['target']),default => false
        }) {
            $reasons[] = 'represented_linear_target_not_met';
        }

        return ['status' => $reasons ? 'rejected' : 'checked', 'reasons' => $reasons, 'spend' => ExactValue::fraction($spend), 'response' => ExactValue::fraction($response),
            'objective' => $objective === null ? null : ExactValue::fraction($objective), 'parts' => $parts, 'feasibility_tolerance' => '0',
            'model_semantics' => 'exact_rational_evaluation_of_binary64_represented_linear_slopes_and_exact_decimal_allocations',
            'source_or_future_guarantee' => false, 'exact_real_optimality_claim' => false];
    }
}
