<?php

namespace App\Services\OperationsResearch\Strategies;

use App\Services\Analytics\Curves\ResponseCurveContract;
use App\Services\OperationsResearch\DTOs\OptimizationResultDto;

/**
 * Water-filling optimizer over arbitrary monotone response curves
 * (Master Spec sections 17-24: fitted adaptive models instead of one assumed
 * curve for everyone).
 *
 * Solves: maximize sum Y_i(x_i) subject to sum x_i = B (exact) or <= B
 * (at_most with an explicit minimum marginal return floor), L_i <= x_i <= U_i.
 * Concave channels get interior KKT solutions; linear channels (constant
 * marginal) produce corner solutions with a deterministic residual split at
 * the tie. The result carries the same solver certificate as the classic
 * optimizer: the solution is CHECKED (budget, bounds, stationarity), not trusted.
 *
 * It says nothing about whether the curves describe reality; that is the
 * fitter's and the UI's honesty layer.
 */
class GenericCurveOptimizer
{
    private const EPSILON = 0.01;
    private const MAX_ITERATIONS = 200;

    /**
     * @param array $channels [ ['channel'=>string,'display_name'=>string,'curve'=>ResponseCurveContract,
     *                          'current_spend'=>float,'current_revenue'=>float,'min'=>float,'max'=>float,
     *                          'model_meta'=>array] ]
     */
    public function optimize(float $totalBudget, array $channels, array $constraints = []): OptimizationResultDto
    {
        if (!is_finite($totalBudget) || $totalBudget <= 0) {
            return OptimizationResultDto::abstain('constraints_required', 'The budget is not a positive finite number. No recommendation was produced.', 0.0);
        }
        if (empty($channels)) {
            return OptimizationResultDto::abstain('insufficient_data', 'No channels with usable curves. No recommendation was produced.', $totalBudget);
        }
        foreach ($channels as $ch) {
            foreach (['current_spend', 'current_revenue', 'min', 'max'] as $k) {
                if (!is_finite((float) $ch[$k])) {
                    return OptimizationResultDto::abstain('constraints_required', 'A channel parameter is not a finite number.', $totalBudget);
                }
            }
            if ($ch['min'] < 0 || $ch['min'] > $ch['max']) {
                return OptimizationResultDto::abstain('infeasible', 'Channel ' . $ch['channel'] . ' has conflicting limits.', $totalBudget);
            }
        }

        $mode = $constraints['budget_mode'] ?? 'exact';
        $minMarginal = isset($constraints['min_marginal_roas']) ? (float) $constraints['min_marginal_roas'] : null;
        if (!in_array($mode,['exact','at_most'],true) || ($minMarginal!==null && (!is_finite($minMarginal) || $mode!=='at_most'))) { return OptimizationResultDto::abstain('constraints_required','A marginal floor applies only to at_most mode; valid budget_mode is required.',$totalBudget); }
        if ($mode === 'at_most' && ($minMarginal === null || $minMarginal <= 0)) {
            return OptimizationResultDto::abstain('constraints_required', 'budget_mode=at_most needs an explicit positive min_marginal_roas.', $totalBudget);
        }

        $sumMin = array_sum(array_map(fn($c) => $c['min'], $channels));
        $sumMax = array_sum(array_map(fn($c) => $c['max'], $channels));
        if ($totalBudget < $sumMin - self::EPSILON) {
            return OptimizationResultDto::abstain('infeasible', sprintf('The channel minimums add up to %s, above the budget %s.', number_format($sumMin, 2), number_format($totalBudget, 2)), $totalBudget);
        }
        if ($mode === 'exact' && $totalBudget > $sumMax + self::EPSILON) {
            return OptimizationResultDto::abstain('infeasible', 'No allocation satisfies the stated maximums for this budget.', $totalBudget);
        }

        // Water-filling: find lambda with sum spendForMarginal(lambda) == budget.
        $hi = 0.0;
        foreach ($channels as $ch) {
            $hi = max($hi, $ch['curve']->marginalAt(0.0));
        }
        $hi = max($hi * 1.5, 10.0);
        $lo = 1e-12;
        $lambda = $this->bisectLambda($channels, $totalBudget, $lo, $hi, $mode === 'at_most' ? $minMarginal : null);
        if ($mode === 'at_most' && $lambda < $minMarginal) {
            $lambda = $minMarginal;
        }

        // Allocate at lambda, then split any residual deterministically across
        // channels whose marginal ties at lambda (linear channels at their rate).
        $alloc = [];
        $spent = 0.0;
        foreach ($channels as $i => $ch) {
            $s = $ch['curve']->spendForMarginal($lambda, $ch['min'], $ch['max']);
            $alloc[$i] = $s;
            $spent += $s;
        }
        $residual = $totalBudget - $spent;
        if (($mode === 'exact' || $lambda >= $minMarginal) && abs($residual) > max(1e-6, 1e-8 * $totalBudget)) {
            $tied = [];
            foreach ($channels as $i => $ch) {
                $mr = $ch['curve']->marginalAt($alloc[$i]);
                if (abs($mr - $lambda) <= 1e-6 * max(1.0, $lambda) && $alloc[$i] > $ch['min'] - 1e-9 && $alloc[$i] < $ch['max'] + 1e-9) {
                    $tied[] = $i;
                }
            }
            if ($residual > 0) {
                $room = [];
                $totalRoom = 0.0;
                foreach ($tied as $i) {
                    $r = $channels[$i]['max'] - $alloc[$i];
                    if ($r > 0) {
                        $room[$i] = $r;
                        $totalRoom += $r;
                    }
                }
                if ($totalRoom > 0 && $residual <= $totalRoom + self::EPSILON) {
                    foreach ($room as $i => $r) {
                        $add = $residual * ($r / $totalRoom);
                        $alloc[$i] += $add;
                    }
                }
            } else {
                $room = [];
                $totalRoom = 0.0;
                foreach ($tied as $i) {
                    $r = $alloc[$i] - $channels[$i]['min'];
                    if ($r > 0) {
                        $room[$i] = $r;
                        $totalRoom += $r;
                    }
                }
                if ($totalRoom > 0 && -$residual <= $totalRoom + self::EPSILON) {
                    foreach ($room as $i => $r) {
                        $alloc[$i] += $residual * ($r / $totalRoom); // residual negative
                    }
                }
            }
        }

        if ($mode==='at_most' && array_sum($alloc)<=1e-9) { return OptimizationResultDto::abstain('no_allocation','No positive allocation meets the marginal-return floor.',$totalBudget); }
        // Certificate: budget, bounds, KKT stationarity.
        $solvedSpend = array_sum($alloc);
        $stationarity = 0.0;
        $boundsOk = true;
        foreach ($channels as $i => $ch) {
            $sp = $alloc[$i];
            if ($sp < $ch['min'] - 1e-6 || $sp > $ch['max'] + 1e-6 || !is_finite($sp)) {
                $boundsOk = false;
            }
            $mr = $ch['curve']->marginalAt($sp);
            $atMin = $sp <= $ch['min'] + 1e-9;
            $atMax = $sp >= $ch['max'] - 1e-9;
            if ($atMin && !$atMax) {
                $stationarity = max($stationarity, max(0.0, $mr - $lambda));
            } elseif ($atMax && !$atMin) {
                $stationarity = max($stationarity, max(0.0, $lambda - $mr));
            } elseif (!$atMin && !$atMax) {
                $stationarity = max($stationarity, abs($mr - $lambda));
            }
        }
        $complementarityResidual=$mode==='at_most' && $solvedSpend<$totalBudget-max(1e-6,1e-8*$totalBudget)?max(0,$lambda-$minMarginal):0.0;
        $budgetResidual = $mode === 'exact' ? abs($solvedSpend - $totalBudget) : max(0.0, $solvedSpend - $totalBudget);
        $budgetTol = max(1e-6, 1e-8 * $totalBudget);
        $statTol = 1e-6 * max(1.0, $lambda);
        $certificate = [
            'budget_residual' => $budgetResidual,
            'budget_tolerance' => $budgetTol,
            'stationarity_residual' => $stationarity,
            'stationarity_tolerance' => $statTol,
            'within_bounds' => $boundsOk,
            'marginal_floor_complementarity_residual'=>$complementarityResidual,
            'scope' => 'Numerical optimality for the fitted/assumed response curves only. It says nothing about whether the curves describe real results, and fitted curves are observational (not causal proof).',
        ];
        if (!$boundsOk || $budgetResidual > $budgetTol || $stationarity > $statTol || $complementarityResidual > $statTol) {
            return OptimizationResultDto::abstain(
                'solver_not_converged',
                'The numerical solution failed its own check (budget, bounds or optimality). No recommendation was produced.',
                $totalBudget
            );
        }

        // Same-spend baseline on the same curves (no budget change mixed into 'gain').
        $currentTotalSpend = array_sum(array_map(fn($c) => $c['current_spend'], $channels));
        $currentTotalRevenue = array_sum(array_column($channels, 'current_revenue'));
        $currentCurveRevenue = 0.0;
        foreach ($channels as $ch) {
            $currentCurveRevenue += $ch['curve']->revenueAt($ch['current_spend']);
        }
        $refSpend = $solvedSpend;
        $baselineTotalRevenue = 0.0;
        $baselineRows = [];
        foreach ($channels as $i => $ch) {
            $bs = $currentTotalSpend > 0 ? $refSpend * ($ch['current_spend'] / $currentTotalSpend) : 0.0;
            $br = $ch['curve']->revenueAt($bs);
            $baselineRows[$i] = [$bs, $br];
            $baselineTotalRevenue += $br;
        }

        $allocOut = [];
        $optimalTotalRevenue = 0.0;
        foreach ($channels as $i => $ch) {
            $sp = $alloc[$i];
            $rev = $ch['curve']->revenueAt($sp);
            $optimalTotalRevenue += $rev;
            $support = $ch['curve']->supportMaxSpend();
            $allocOut[] = [
                'channel' => $ch['channel'],
                'display_name' => $ch['display_name'],
                'model' => $ch['curve']->name(),
                'model_params' => $ch['curve']->parameters(),
                'current_spend' => round($ch['current_spend'], 2),
                'current_revenue' => round($ch['current_revenue'], 2),
                'modeled_revenue_at_observed_spend' => round($ch['curve']->revenueAt($ch['current_spend']), 2),
                'current_roas' => $ch['current_spend'] > 0 ? round($ch['current_revenue'] / $ch['current_spend'], 2) : 0.0,
                'solver_spend' => $sp, // Full precision for downstream scenario certificates.
                'optimal_spend' => round($sp, 2),
                'optimal_revenue' => round($rev, 2),
                'optimal_roas' => $sp > 0 ? round($rev / $sp, 2) : 0.0,
                'baseline_spend_same_budget' => round($baselineRows[$i][0], 2),
                'baseline_revenue_same_budget' => round($baselineRows[$i][1], 2),
                'delta_vs_baseline' => round($sp - $baselineRows[$i][0], 2),
                'delta_vs_baseline_percentage' => $baselineRows[$i][0] > 0 ? round((($sp - $baselineRows[$i][0]) / $baselineRows[$i][0]) * 100, 1) : 0.0,
                'lift_vs_baseline' => round($rev - $baselineRows[$i][1], 2),
                'delta_spend' => round($sp - $ch['current_spend'], 2),
                'delta_spend_percentage' => $ch['current_spend'] > 0 ? round(100 * ($sp - $ch['current_spend']) / $ch['current_spend'], 1) : null,
                'marginal_roas_current' => round($ch['curve']->marginalAt($ch['current_spend']), 4),
                'marginal_roas_optimal' => round($ch['curve']->marginalAt($sp), 4),
                'support_min_spend' => method_exists($ch['curve'], 'supportMinSpend') ? $ch['curve']->supportMinSpend() : 0.0,
                'extrapolates_beyond_support' => ($support > 0 && $sp > $support) || (method_exists($ch['curve'], 'supportMinSpend') && $sp < $ch['curve']->supportMinSpend()),
                'action' => $this->actionFor($sp - $baselineRows[$i][0], $baselineRows[$i][0]),
            ];
        }

        $optimalBlendedRoas = $solvedSpend > 0 ? $optimalTotalRevenue / $solvedSpend : 0.0;
        $baselineRoas = $refSpend > 0 ? $baselineTotalRevenue / $refSpend : 0.0;

        return new OptimizationResultDto(
            totalBudget: $totalBudget,
            currentTotalRevenue: $currentTotalRevenue,
            currentBlendedRoas: $currentTotalSpend > 0 ? $currentTotalRevenue / $currentTotalSpend : 0.0,
            optimalTotalRevenue: $optimalTotalRevenue,
            optimalBlendedRoas: $optimalBlendedRoas,
            revenueGainDelta: $optimalTotalRevenue - $baselineTotalRevenue,
            roasGainDelta: $optimalBlendedRoas - $baselineRoas,
            equilibriumLambda: $lambda,
            channelAllocations: $allocOut,
            recommendations: [],
            frontierCurve: [],
            sensitivityPoints: [],
            mathematicalModel: 'Adaptive fitted response curves (per-channel model selection with validation); water-filling solution of the equal-marginal-return condition',
            status: 'ok',
            statusMessage: 'Scenario under fitted curves (observational). Not an operational recommendation.',
            certificate: $certificate,
            comparison: [
                'observed_spend' => round($currentTotalSpend, 2),
                'observed_revenue' => round($currentTotalRevenue, 2),
                'observed_revenue_on_curve' => round($currentCurveRevenue, 2),
                'spend_change_vs_observed' => round($solvedSpend - $currentTotalSpend, 2),
                'revenue_change_vs_observed' => round($optimalTotalRevenue - $currentTotalRevenue, 2),
                'reference_spend' => round($refSpend, 2),
                'baseline_revenue_same_spend' => round($baselineTotalRevenue, 2),
                'baseline_roas_same_spend' => round($baselineRoas, 4),
                'reallocation_gain' => round($optimalTotalRevenue - $baselineTotalRevenue, 2),
                'definition' => 'Reallocation gain compares the scenario with your current channel mix scaled to the same total spend on the same fitted curves. Neither is profit.',
            ]
        );
    }

    private function bisectLambda(array $channels, float $budget, float $lo, float $hi, ?float $floor): float
    {
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $mid = ($lo + $hi) / 2;
            $sum = 0.0;
            foreach ($channels as $ch) {
                $sum += $ch['curve']->spendForMarginal($mid, $ch['min'], $ch['max']);
            }
            if (abs($sum - $budget) <= 1e-9 * max(1.0, $budget)) {
                return $mid;
            }
            if ($sum > $budget) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return ($lo + $hi) / 2;
    }

    private function actionFor(float $delta, float $current): string
    {
        $band = (float) config('or.maintain_band_percent', 5) / 100.0;
        $ref = max($current, 1e-9);
        if (abs($delta) <= $band * $ref) {
            return 'MAINTAIN';
        }
        return $delta > 0 ? 'SCALE_UP' : 'SCALE_DOWN';
    }
}
