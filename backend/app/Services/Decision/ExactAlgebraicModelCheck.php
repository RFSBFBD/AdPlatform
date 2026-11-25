<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;

/** Rational checks for the declared algebraic family, not exact fitted parameter truth. */
final class ExactAlgebraicModelCheck
{
    public function check(DecisionProblem $problem, array $models, array $allocation): array
    {
        $p = $problem->jsonSerialize();
        $response = BigRational::zero();
        $spend = BigRational::zero();
        $parts = [];
        foreach ($p['variables'] as $v) {
            $id = $v['id'];
            $curve = $models[$id]['curve'];
            if (! in_array($curve->name(), ['linear', 'piecewise', 'quadratic_spline'], true)) {
                return ['status' => 'unavailable', 'reason' => 'all_models_must_have_supported_algebraic_form'];
            }
            $params = $curve->parameters();
            $a = Binary64Boundary::rational($params['a']);
            $b = Binary64Boundary::rational($params['b']);
            $x = ExactValue::of($allocation[$id])->rational();
            $k = isset($params['knot']) ? Binary64Boundary::rational($params['knot']) : null;
            $value = match ($curve->name()) {
                'linear' => $a->multipliedBy($x),
                'piecewise' => $a->multipliedBy($x->isLessThanOrEqualTo($k) ? $x : $k->plus($b->multipliedBy($x->minus($k)))),
                'quadratic_spline' => $a->multipliedBy($x->isLessThanOrEqualTo($k)
                    ? $x->minus(BigRational::one()->minus($b)->multipliedBy($x)->multipliedBy($x)->dividedBy($k->multipliedBy(2)))
                    : $k->multipliedBy(BigRational::one()->plus($b))->dividedBy(2)->plus($b->multipliedBy($x->minus($k)))),
            };
            $scale = isset($p['risk']) ? ExactValue::of($p['risk']['bounds'][$id]['lower_scale'])->rational() : BigRational::one();
            $value = $value->multipliedBy($scale);
            $parts[$id] = ExactValue::fraction($value);
            $response = $response->plus($value);
            $spend = $spend->plus($x);
        }
        $objective = match ($p['objective']) {
            'MINIMIZE_CPA', 'TARGET_CPA' => $response->isZero() ? null : $spend->dividedBy($response),
            'MAXIMIZE_ROAS', 'TARGET_ROAS' => $spend->isZero() ? null : $response->dividedBy($spend), default => $response,
        };
        $reasons = [];
        if ($response->isLessThan($p['minimum_response'])) {
            $reasons[] = 'represented_algebraic_response_floor_not_met';
        }
        if ($objective === null) {
            $reasons[] = 'undefined_represented_algebraic_objective';
        }
        if (isset($p['target']) && match ($p['objective']) {
            'TARGET_REVENUE' => $response->isLessThan($p['target']),
            'TARGET_CPA' => $objective === null || $objective->isGreaterThan($p['target']),
            'TARGET_ROAS' => $objective === null || $objective->isLessThan($p['target']), default => false,
        }) {
            $reasons[] = 'represented_algebraic_target_not_met';
        }

        return ['status' => $reasons ? 'rejected' : 'checked', 'reasons' => $reasons, 'response' => ExactValue::fraction($response),
            'objective' => $objective === null ? null : ExactValue::fraction($objective), 'parts' => $parts, 'feasibility_tolerance' => '0',
            'semantics' => 'Exact rational expression using represented binary64 curve parameters, exact decimal allocation and declared exact decimal lower risk scale. Separate from binary64 evaluator; no exact empirical, transcendental, optimality or future claim.'];
    }
}
