<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use Brick\Math\BigRational;

/** Small exact-feasibility reference solver, independent of the result checker implementation. */
final class FiniteMenuReferenceSolver
{
    public function solve(DecisionProblem $problem, array $models): array
    {
        $p = $problem->jsonSerialize();
        $winner = null;
        $bestScore = null;
        $examined = 0;
        $feasible = 0;
        $walk = function (int $index, array $allocation) use (&$walk, &$winner, &$bestScore, &$examined, &$feasible, $p, $models): void {
            if ($index < count($p['variables'])) {
                $v = $p['variables'][$index];
                $menu = $v['menu'];
                usort($menu, fn ($a, $b) => BigRational::of($a)->compareTo($b));
                foreach ($menu as $point) {
                    $walk($index + 1, $allocation + [$v['id'] => $point]);
                }

                return;
            }
            $examined++;
            $spent = BigRational::zero();
            $response = 0.0;
            $linearResponse = BigRational::zero();
            $allLinear = true;
            $allAlgebraic = true;
            $algebraicResponse = BigRational::zero();
            foreach ($p['variables'] as $v) {
                $x = BigRational::of($allocation[$v['id']]);
                if ($x->compareTo($v['minimum']) < 0 || $x->compareTo($v['maximum']) > 0) {
                    return;
                }
                $spent = $spent->plus($x);
                $curve = $models[$v['id']]['curve'];
                $numeric = (float) $allocation[$v['id']];
                if (! is_finite($numeric) || $numeric > 1e12 || Binary64Boundary::loss($allocation[$v['id']], $numeric)->isGreaterThan('0.0001') || $numeric > $curve->supportMaxSpend()
                    || $numeric < (method_exists($curve, 'supportMinSpend') ? $curve->supportMinSpend() : 0)) {
                    return;
                }
                $scale = isset($p['risk']) ? (float) $p['risk']['bounds'][$v['id']]['lower_scale'] : 1.0;
                $part = $scale * $curve->revenueAt($numeric);
                if (! is_finite($part) || $part < 0) {
                    return;
                }
                $response += $part;
                $parameters = $curve->parameters();
                if (in_array($curve->name(), ['linear', 'piecewise', 'quadratic_spline'], true)) {
                    $a = Binary64Boundary::rational($parameters['a']);
                    $b = Binary64Boundary::rational($parameters['b']);
                    $k = isset($parameters['knot']) ? Binary64Boundary::rational($parameters['knot']) : null;
                    if ($curve->name() === 'linear') {
                        $algebraicPart = $a->multipliedBy($x);
                    } elseif ($x->isGreaterThan($k)) {
                        $atKnot = $curve->name() === 'piecewise' ? $k : $k->multipliedBy(BigRational::one()->plus($b))->dividedBy(2);
                        $algebraicPart = $a->multipliedBy($atKnot->plus($b->multipliedBy($x->minus($k))));
                    } else {
                        $algebraicPart = $curve->name() === 'piecewise' ? $a->multipliedBy($x) : $a->multipliedBy($x)->minus($a->multipliedBy(BigRational::one()->minus($b))->multipliedBy($x)->multipliedBy($x)->dividedBy($k->multipliedBy(2)));
                    }
                    $algebraicResponse = $algebraicResponse->plus($algebraicPart->multipliedBy(isset($p['risk']) ? $p['risk']['bounds'][$v['id']]['lower_scale'] : '1'));
                } else {
                    $allAlgebraic = false;
                }
                if ($curve->name() === 'linear') {
                    $slope = $curve->parameters()['a'] * $scale;
                    if (! is_finite($slope) || $slope < 0) {
                        return;
                    }
                    $linearResponse = $linearResponse->plus(Binary64Boundary::rational($slope)->multipliedBy($x));
                } else {
                    $allLinear = false;
                }
            }
            if ($spent->compareTo($p['budget_cap']) > 0 || $spent->compareTo($p['minimum_spend']) < 0
                || ! is_finite($response) || $response < (float) $p['minimum_response']) {
                return;
            }
            foreach ($p['groups'] as $g) {
                $subtotal = BigRational::zero();
                foreach ($g['members'] as $id) {
                    $subtotal = $subtotal->plus($allocation[$id]);
                }
                if ($subtotal->compareTo($g['minimum']) < 0 || $subtotal->compareTo($g['maximum']) > 0) {
                    return;
                }
            }
            $s = $spent->toFloat();
            $ratio = match ($p['objective']) {
                'MINIMIZE_CPA', 'TARGET_CPA' => $response > 0 ? $s / $response : null,
                'MAXIMIZE_ROAS', 'TARGET_ROAS' => $s > 0 ? $response / $s : null,
                default => $response,
            };
            if ($ratio === null || ! is_finite($ratio)) {
                return;
            }
            if (isset($p['target']) && match ($p['objective']) {
                'TARGET_CPA' => $ratio > (float) $p['target'],
                'TARGET_ROAS' => $ratio < (float) $p['target'],
                'TARGET_REVENUE' => $response < (float) $p['target'],
                default => false,
            }) {
                return;
            }
            if ($allLinear) {
                if ($linearResponse->isLessThan($p['minimum_response'])) {
                    return;
                }
                $exactObjective = match ($p['objective']) {
                    'MINIMIZE_CPA', 'TARGET_CPA' => $linearResponse->isZero() ? null : $spent->dividedBy($linearResponse),
                    'MAXIMIZE_ROAS', 'TARGET_ROAS' => $spent->isZero() ? null : $linearResponse->dividedBy($spent), default => $linearResponse
                };
                if ($exactObjective === null || (isset($p['target']) && match ($p['objective']) {
                    'TARGET_REVENUE' => $linearResponse->isLessThan($p['target']),
                    'TARGET_CPA' => $exactObjective->isGreaterThan($p['target']),
                    'TARGET_ROAS' => $exactObjective->isLessThan($p['target']), default => false
                })) {
                    return;
                }
            }
            if ($allAlgebraic && ! $allLinear) {
                if ($algebraicResponse->isLessThan($p['minimum_response'])) {
                    return;
                }
                $algebraicObjective = match ($p['objective']) {
                    'MINIMIZE_CPA','TARGET_CPA' => $algebraicResponse->isZero() ? null : $spent->dividedBy($algebraicResponse),
                    'MAXIMIZE_ROAS','TARGET_ROAS' => $spent->isZero() ? null : $algebraicResponse->dividedBy($spent), default => $algebraicResponse,
                };
                if ($algebraicObjective === null || isset($p['target']) && match ($p['objective']) {
                    'TARGET_REVENUE' => $algebraicResponse->isLessThan($p['target']),
                    'TARGET_CPA' => $algebraicObjective->isGreaterThan($p['target']),
                    'TARGET_ROAS' => $algebraicObjective->isLessThan($p['target']), default => false,
                }) {
                    return;
                }
            }
            $feasible++;
            $minimizeSpend = str_starts_with($p['objective'], 'TARGET_') && $p['target_optimization'] === 'minimize_spend';
            $score = $minimizeSpend ? -$s : match ($p['objective']) {
                'MINIMIZE_CPA' => -$ratio, 'TARGET_CPA','TARGET_ROAS','TARGET_REVENUE' => $response, default => $ratio,
            };
            $exactScore = $minimizeSpend ? $spent->negated() : ($allLinear ? match ($p['objective']) {
                'MINIMIZE_CPA' => $exactObjective->negated(), 'TARGET_CPA','TARGET_ROAS','TARGET_REVENUE' => $linearResponse, default => $exactObjective,
            } : null);
            $better = $winner === null || ($exactScore !== null ? $exactScore->compareTo($bestScore) > 0 : $score > $bestScore);
            if ($better) {
                $winner = $allocation;
                $bestScore = $exactScore ?? $score;
            }

        };
        $walk(0, []);

        return ['allocation' => $winner, 'examined' => $examined, 'feasible' => $feasible, 'ranking_arithmetic' => str_starts_with($p['objective'], 'TARGET_') && $p['target_optimization'] === 'minimize_spend' ? 'exact_decimal_spend' : (count(array_filter($p['variables'], fn ($v) => $models[$v['id']]['curve']->name() === 'linear')) === count($p['variables']) ? 'exact_rational_represented_linear_model' : 'binary64_modeled_objective')];
    }
}
