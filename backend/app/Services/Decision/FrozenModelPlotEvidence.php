<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use InvalidArgumentException;

/** Explicit menu plot operands from frozen curves. Finite differences test implementation, not empirical truth. */
final class FrozenModelPlotEvidence
{
    public function build(array $result): array
    {
        if (($result['route']['status'] ?? null) !== 'admitted') {
            return ['status' => 'unavailable', 'reason' => 'models_not_admitted'];
        }
        $curves = [];
        foreach ($result['problem']['variables'] as $v) {
            $frozen = $result['model_snapshot'][$v['id']]['curve'] ?? null;
            if (! is_array($frozen)) {
                return ['status' => 'unavailable', 'reason' => 'frozen_curve_required'];
            }
            try {
                $curve = $this->restore($frozen);
            } catch (InvalidArgumentException $e) {
                return ['status' => 'unavailable', 'reason' => $e->getMessage()];
            }
            $points = [];
            $menu = $result['problem']['allocation_mode'] ?? 'finite_menu';
            if ($menu !== 'finite_menu') {
                $lower = ExactValue::of($v['minimum'])->decimal();
                $span = ExactValue::of($v['maximum'])->decimal()->minus($lower);
                $step = $span->multipliedBy('0.05');
                $menu = [];
                for ($i = 0; $i <= 20; $i++) {
                    $menu[] = (string) $lower->plus($step->multipliedBy($i));
                }
                $menu = array_values(array_unique($menu));
            } else {
                $menu = $v['menu'];
            }
            usort($menu, fn ($a, $b) => ExactValue::of($a)->decimal()->compareTo($b));
            foreach ($menu as $token) {
                $x = (float) $token;
                $lo = $frozen['support_min'];
                $hi = $frozen['support_max'];
                if (! is_finite($x) || $x > 1e12 || Binary64Boundary::loss((string) $token, $x)->isGreaterThan('0.0001') || $x < $lo || $x > $hi) {
                    return ['status' => 'unavailable', 'reason' => 'plot_operand_range_loss_or_support'];
                }
                $response = $curve->revenueAt($x);
                $marginal = $curve->marginalAt($x);
                if (! is_finite($response) || $response < 0 || ! is_finite($marginal) || $marginal < 0) {
                    return ['status' => 'unavailable', 'reason' => 'nonfinite_or_negative_frozen_plot'];
                }
                $step = min(max(1e-6, abs($x) * 1e-5), max(0, ($hi - $lo) / 100));
                $left = max($lo, $x - $step);
                $right = min($hi, $x + $step);
                $knot = $frozen['parameters']['knot'] ?? null;
                $kink = $frozen['name'] === 'piecewise' && $knot !== null && $left <= $knot && $right >= $knot;
                $finiteDifference = $right > $left && ! $kink ? ($curve->revenueAt($right) - $curve->revenueAt($left)) / ($right - $left) : null;
                $points[] = ['spend_exact' => (string) $token, 'response_approximate' => $response, 'marginal_approximate' => $marginal,
                    'marginal_check' => ['finite_difference_approximate' => $finiteDifference, 'absolute_difference_approximate' => $finiteDifference === null ? null : abs($finiteDifference - $marginal),
                        'method' => $kink ? 'not_checked_across_piecewise_kink' : ($right > $left ? 'support_bounded_secant' : 'unavailable_singleton_support'),
                        'right_derivative_convention' => $frozen['name'] === 'piecewise']];
            }
            $curves[$v['id']] = ['model' => $frozen['name'], 'type' => 'SCENARIO', 'source_snapshot_id' => $result['snapshot_id'], 'points' => $points,
                'period_days' => $result['problem']['horizon_days'], 'period_assumption' => $result['model_snapshot'][$v['id']]['period_assumption'] ?? 'explicit_frozen_period_curve',
                'response_unit' => $result['problem']['response_unit'], 'marginal_unit' => $result['problem']['response_unit'].'/'.$result['problem']['currency'],
                'sample_policy' => $result['problem']['allocation_mode'] ?? 'finite_menu', 'interpolation_claim' => false, 'outside_observed_support' => false, 'causal_claim' => false];
        }

        return ['status' => 'available_data', 'snapshot_id' => $result['snapshot_id'], 'curves' => $curves, 'rendered' => false,
            'limits' => 'Only explicit menu points or a21-point exact-decimal continuous-domain grid evaluated; no approximation error bound between grid points. Numerical secants are implementation diagnostics, not statistical validation or universal derivative error bounds.'];
    }

    public function restore(array $frozen): ResponseCurve
    {
        $p = $frozen['parameters'];
        $max = $frozen['support_max'];
        $min = $frozen['support_min'];

        return match ($frozen['name']) {
            'linear' => ResponseCurve::linear($p['a'], $max, $min),'log' => ResponseCurve::log($p['a'], $p['b'], $max, $min),
            'power' => ResponseCurve::power($p['a'], $p['b'], $max, $min),'saturation' => ResponseCurve::saturation($p['a'], $p['b'], $max, $min),
            'piecewise' => ResponseCurve::piecewise($p['a'], $p['b'], $p['knot'], $max, $min),
            'quadratic_spline' => ResponseCurve::quadraticSpline($p['a'], $p['b'], $p['knot'], $max, $min),
            default => throw new InvalidArgumentException('unsupported_frozen_curve')
        };
    }
}
