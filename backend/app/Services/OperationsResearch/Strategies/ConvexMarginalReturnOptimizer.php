<?php

namespace App\Services\OperationsResearch\Strategies;

use App\Services\OperationsResearch\Contracts\OptimizationStrategyInterface;
use App\Services\OperationsResearch\DTOs\ChannelParameterDto;
use App\Services\OperationsResearch\DTOs\OptimizationResultDto;

class ConvexMarginalReturnOptimizer implements OptimizationStrategyInterface
{
    /**
     * Precision tolerance for budget matching in optimization
     */
    private const EPSILON = 0.01;

    /**
     * Maximum bisection iterations
     */
    private const MAX_ITERATIONS = 100;

    public function optimize(float $totalBudget, array $channelParams, array $constraints = []): OptimizationResultDto
    {
        if (!is_finite($totalBudget)) {
            return OptimizationResultDto::abstain('constraints_required', 'The budget is not a finite number. No recommendation was produced.', 0.0);
        }
        foreach ($channelParams as $cp) {
            /** @var ChannelParameterDto $cp */
            foreach ([$cp->alpha, $cp->beta, $cp->minSpend, $cp->maxSpend, $cp->currentSpend] as $v) {
                if (!is_finite($v)) {
                    return OptimizationResultDto::abstain('constraints_required', 'A channel parameter is not a finite number. No recommendation was produced.', max(0.0, $totalBudget));
                }
            }
            if ($cp->alpha <= 0.0 || $cp->beta <= 0.0 || $cp->minSpend < 0.0) {
                return OptimizationResultDto::abstain('constraints_required', 'Channel ' . $cp->channel . ' has invalid parameters (non-positive curve or negative minimum).', max(0.0, $totalBudget));
            }
            if ($totalBudget > 0 && $cp->minSpend > $totalBudget + self::EPSILON) {
                return OptimizationResultDto::abstain('infeasible', sprintf('The channel minimums add up to at least %s (channel %s alone), which is more than the budget %s. Lower the minimum or raise the budget.', number_format($cp->minSpend, 2, '.', ''), $cp->channel, number_format($totalBudget, 2, '.', '')), $totalBudget);
            }
            if ($cp->minSpend > $cp->maxSpend) {
                return OptimizationResultDto::abstain('infeasible', 'Channel ' . $cp->channel . ' has a minimum above its maximum: the limits conflict.', max(0.0, $totalBudget));
            }
        }
        if (empty($channelParams) || $totalBudget <= 0) {
            return OptimizationResultDto::abstain(
                'insufficient_data',
                'No channels with usable data or a non-positive budget: no recommendation was produced.',
                max(0.0, $totalBudget)
            );
        }

        // Budget mode: 'exact' must spend the whole budget; 'at_most' may under-spend
        // (stops once marginal return falls below an explicit minimum marginal ROAS).
        $mode = $constraints['budget_mode'] ?? 'exact';
        $minMarginalRoas = isset($constraints['min_marginal_roas']) ? (float) $constraints['min_marginal_roas'] : null;
        if ($mode === 'at_most' && ($minMarginalRoas === null || $minMarginalRoas <= 0.0)) {
            return OptimizationResultDto::abstain(
                'constraints_required',
                'budget_mode=at_most needs an explicit positive min_marginal_roas; none was assumed.',
                $totalBudget
            );
        }

        // Feasibility: no solution exists inside the stated per-channel limits.
        $sumMin = 0.0;
        $sumMax = 0.0;
        foreach ($channelParams as $param) {
            /** @var ChannelParameterDto $param */
            $sumMin += $param->minSpend;
            $sumMax += $param->maxSpend;
        }
        $overMax = $mode === 'exact' && $totalBudget > $sumMax + self::EPSILON;
        if ($totalBudget < $sumMin - self::EPSILON) {
            return OptimizationResultDto::abstain(
                'infeasible',
                sprintf('The channel minimums add up to %s, which is more than the budget %s. Lower a minimum or raise the budget.', number_format($sumMin, 2, '.', ''), number_format($totalBudget, 2, '.', '')),
                $totalBudget
            );
        }
        if ($overMax) {
            return OptimizationResultDto::abstain(
                'infeasible',
                sprintf(
                    'No allocation satisfies the stated limits: budget %s, per-channel minimums total %s, maximums total %s.',
                    number_format($totalBudget, 2, '.', ''),
                    number_format($sumMin, 2, '.', ''),
                    number_format($sumMax, 2, '.', '')
                ),
                $totalBudget
            );
        }

        // 1. Calculate baseline current performance
        $currentTotalRevenue = 0.0;
        $currentTotalSpend = 0.0;
        foreach ($channelParams as $param) {
            /** @var ChannelParameterDto $param */
            $currentTotalSpend += $param->currentSpend;
            $currentTotalRevenue += $param->calculateRevenue($param->currentSpend);
        }
        $currentBlendedRoas = $currentTotalSpend > 0 ? ($currentTotalRevenue / $currentTotalSpend) : 0.0;

        // 2. Solve for optimal lambda using bisection search
        $lambda = $this->solveEquilibriumLambda($totalBudget, $channelParams);
        if ($mode === 'at_most' && $lambda < $minMarginalRoas) {
            // Spending the full budget would push marginal ROAS below the stated floor: under-spend.
            $lambda = $minMarginalRoas;
        }

        // Post-solve certificate: the solution is checked, not trusted.
        // Budget residual, bounds, and KKT stationarity/complementarity at the returned lambda.
        $solvedSpend = 0.0;
        $stationarity = 0.0;
        $boundsOk = true;
        $feasTol = 1e-9;
        foreach ($channelParams as $param) {
            $sp = $param->spendForMarginalReturn($lambda);
            $solvedSpend += $sp;
            if ($sp < $param->minSpend - $feasTol || $sp > $param->maxSpend + $feasTol || !is_finite($sp)) {
                $boundsOk = false;
            }
            $mr = $param->marginalReturn($sp);
            $atMin = $sp <= $param->minSpend + 1e-9;
            $atMax = $sp >= $param->maxSpend - 1e-9;
            if ($atMin && !$atMax) {
                $stationarity = max($stationarity, max(0.0, $mr - $lambda));      // at lower bound: marginal must not exceed lambda
            } elseif ($atMax && !$atMin) {
                $stationarity = max($stationarity, max(0.0, $lambda - $mr));      // at upper bound: marginal must not fall below lambda
            } elseif (!$atMin && !$atMax) {
                $stationarity = max($stationarity, abs($mr - $lambda));            // interior: marginal equals lambda
            }
        }
        $budgetResidual = $mode === 'exact' ? abs($solvedSpend - $totalBudget) : max(0.0, $solvedSpend - $totalBudget);
        $budgetTol = max(1e-6, 1e-8 * $totalBudget);
        $statTol = 1e-6 * max(1.0, $lambda);
        $certificate = [
            'budget_residual' => $budgetResidual,
            'budget_tolerance' => $budgetTol,
            'stationarity_residual' => $stationarity,
            'stationarity_tolerance' => $statTol,
            'within_bounds' => $boundsOk,
            'scope' => 'Numerical optimality for the assumed response curve only. It says nothing about whether the curve describes real results.',
        ];
        if ($mode === 'exact' && $solvedSpend < $totalBudget - $budgetTol && $lambda <= 1e-9) {
            return OptimizationResultDto::abstain(
                'budget_beyond_numeric_range',
                'At this budget the assumed curve is saturated: the marginal return per unit falls below the solver\'s numeric floor (1e-12), so the exact split cannot be computed reliably. This is a numeric limit of the assumed curve, not a proof that the budget is infeasible. No recommendation was produced.',
                $totalBudget
            );
        }
        if (!$boundsOk || $budgetResidual > $budgetTol || $stationarity > $statTol) {
            return OptimizationResultDto::abstain(
                'solver_not_converged',
                'The numerical solution failed its own check (budget, bounds or optimality). No recommendation was produced.',
                $totalBudget
            );
        }

        // 3. Compute optimal allocation per channel at lambda
        $channelAllocations = [];
        $optimalTotalRevenue = 0.0;
        $totalOptimalSpend = 0.0;
        foreach ($channelParams as $param) {
            $totalOptimalSpend += $param->spendForMarginalReturn($lambda);
        }
        // Same-spend baseline: the CURRENT channel mix scaled to the spend the scenario actually uses,
        // evaluated on the same assumed curve. Comparing against observed spend would mix a budget change into 'gain'.
        $refSpend = $totalOptimalSpend;
        $baselineRows = [];
        $baselineTotalRevenue = 0.0;
        foreach ($channelParams as $param) {
            $bs = $currentTotalSpend > 0 ? $refSpend * ($param->currentSpend / $currentTotalSpend) : 0.0;
            $br = $param->calculateRevenue($bs);
            $baselineRows[$param->channel] = [$bs, $br];
            $baselineTotalRevenue += $br;
        }
        $totalOptimalSpend = 0.0;

        foreach ($channelParams as $param) {
            /** @var ChannelParameterDto $param */
            $optSpend = $param->spendForMarginalReturn($lambda);
            $optRevenue = $param->calculateRevenue($optSpend);
            $optRoas = $optSpend > 0 ? ($optRevenue / $optSpend) : 0.0;
            $deltaSpend = $optSpend - $param->currentSpend;
            $marginalAtOptimal = $param->marginalReturn($optSpend);
            $marginalAtCurrent = $param->marginalReturn($param->currentSpend);

            $optimalTotalRevenue += $optRevenue;
            $totalOptimalSpend += $optSpend;

            $channelAllocations[] = [
                'channel' => $param->channel,
                'display_name' => $param->displayName,
                'current_spend' => round($param->currentSpend, 2),
                'current_revenue' => round($param->calculateRevenue($param->currentSpend), 2),
                'current_roas' => round($param->currentRoas, 2),
                'optimal_spend' => round($optSpend, 2),
                'optimal_revenue' => round($optRevenue, 2),
                'optimal_roas' => round($optRoas, 2),
                'baseline_spend_same_budget' => round($baselineRows[$param->channel][0], 2),
                'baseline_revenue_same_budget' => round($baselineRows[$param->channel][1], 2),
                'delta_vs_baseline' => round($optSpend - $baselineRows[$param->channel][0], 2),
                'delta_vs_baseline_percentage' => $baselineRows[$param->channel][0] > 0 ? round((($optSpend - $baselineRows[$param->channel][0]) / $baselineRows[$param->channel][0]) * 100, 1) : 0.0,
                'lift_vs_baseline' => round($optRevenue - $baselineRows[$param->channel][1], 2),
                'delta_spend' => round($deltaSpend, 2),
                'delta_spend_percentage' => $param->currentSpend > 0 ? round(($deltaSpend / $param->currentSpend) * 100, 1) : 0.0,
                'marginal_roas_current' => round($marginalAtCurrent, 3),
                'marginal_roas_optimal' => round($marginalAtOptimal, 3),
                'action' => $this->actionFor($optSpend - $baselineRows[$param->channel][0], $baselineRows[$param->channel][0]),
            ];
        }

        $optimalBlendedRoas = $totalOptimalSpend > 0 ? ($optimalTotalRevenue / $totalOptimalSpend) : 0.0;
        // Signed, same-spend comparison. Can be negative when stated limits force a worse mix than the current one.
        $revenueGainDelta = $optimalTotalRevenue - $baselineTotalRevenue;
        $baselineRoas = $refSpend > 0 ? $baselineTotalRevenue / $refSpend : 0.0;
        $roasGainDelta = $optimalBlendedRoas - $baselineRoas;
        $comparison = [
            'observed_spend' => round($currentTotalSpend, 2),
            'observed_revenue_on_curve' => round($currentTotalRevenue, 2),
            'reference_spend' => round($refSpend, 2),
            'baseline_revenue_same_spend' => round($baselineTotalRevenue, 2),
            'baseline_roas_same_spend' => round($baselineRoas, 4),
            'reallocation_gain' => round($revenueGainDelta, 2),
            'spend_change_vs_observed' => round($refSpend - $currentTotalSpend, 2),
            'revenue_change_vs_observed' => round($optimalTotalRevenue - $currentTotalRevenue, 2),
            'definition' => 'Reallocation gain compares the scenario with your current channel mix scaled to the same total spend on the same assumed curve. The change in total spend and in revenue versus the observed 30 days are shown separately. Neither is profit.',
        ];

        // 4. Generate deterministic, actionable mathematical recommendations
        $recommendations = $this->generateRecommendations($channelAllocations, $revenueGainDelta);

        // 5. Generate efficiency frontier curve (10 discrete budget points from 25% to 200% of budget)
        $frontierCurve = $this->generateFrontierCurve($totalBudget, $channelParams);

        // 6. Generate sensitivity points per channel (Spend vs Revenue curve)
        $sensitivityPoints = $this->generateSensitivityCurves($channelParams, $totalBudget);

        return new OptimizationResultDto(
            totalBudget: $totalBudget,
            currentTotalRevenue: $currentTotalRevenue,
            currentBlendedRoas: $currentBlendedRoas,
            optimalTotalRevenue: $optimalTotalRevenue,
            optimalBlendedRoas: $optimalBlendedRoas,
            revenueGainDelta: $revenueGainDelta,
            roasGainDelta: $roasGainDelta,
            equilibriumLambda: $lambda,
            channelAllocations: $channelAllocations,
            recommendations: $recommendations,
            frontierCurve: $frontierCurve,
            sensitivityPoints: $sensitivityPoints,
            certificate: $certificate,
            comparison: $comparison
        );
    }

    /**
     * Bisection search for lambda such that sum(S_i(lambda)) == totalBudget
     */
    private function solveEquilibriumLambda(float $totalBudget, array $channelParams): float
    {
        // Determine search bounds for lambda
        $low = 1e-12;
        $high = 0.0;
        foreach ($channelParams as $param) {
            /** @var ChannelParameterDto $param */
            $maxMarginal = $param->alpha * $param->beta;
            if ($maxMarginal > $high) {
                $high = $maxMarginal;
            }
        }
        $high = max($high * 1.5, 10.0);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $mid = ($low + $high) / 2.0;
            $sumSpend = 0.0;

            foreach ($channelParams as $param) {
                /** @var ChannelParameterDto $param */
                $sumSpend += $param->spendForMarginalReturn($mid);
            }

            $diff = $sumSpend - $totalBudget;

            if (abs($diff) <= 1e-9 * max(1.0, $totalBudget)) {
                return $mid;
            }

            // Since S(lambda) is strictly decreasing with lambda:
            // If sumSpend > totalBudget, lambda must increase to reduce spend
            if ($sumSpend > $totalBudget) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2.0;
    }

    /** Label only: relative change band from config('or.maintain_band_percent'), reported in `gates`. */
    private function actionFor(float $delta, float $current): string
    {
        $band = (float) config('or.maintain_band_percent', 5) / 100.0;
        $ref = max($current, 1e-9);
        if (abs($delta) <= $band * $ref) {
            return 'MAINTAIN';
        }
        return $delta > 0 ? 'SCALE_UP' : 'SCALE_DOWN';
    }

    private function generateRecommendations(array $allocations, float $revenueGain): array
    {
        $recommendations = [];

        // Identify scale down candidates
        $scaleDown = array_filter($allocations, fn($a) => $a['action'] === 'SCALE_DOWN');
        // Identify scale up candidates
        $scaleUp = array_filter($allocations, fn($a) => $a['action'] === 'SCALE_UP');

        usort($scaleDown, fn($a, $b) => $a['delta_vs_baseline'] <=> $b['delta_vs_baseline']); // Most negative first
        usort($scaleUp, fn($a, $b) => $b['delta_vs_baseline'] <=> $a['delta_vs_baseline']);   // Most positive first

        if (!empty($scaleDown) && !empty($scaleUp)) {
            $topSource = $scaleDown[0];
            $topDest = $scaleUp[0];
            $shiftAmount = min(abs($topSource['delta_vs_baseline']), $topDest['delta_vs_baseline']);

            $recommendations[] = [
                'type' => 'BUDGET_REALLOCATION',
                'severity' => 'HIGH_IMPACT',
                'title' => sprintf(
                    'Scenario: reallocate %s from %s to %s',
                    number_format($shiftAmount, 0),
                    $topSource['display_name'],
                    $topDest['display_name']
                ),
                'description' => sprintf(
                    '%s shows lower marginal ROAS under the assumed curve (%sx). Shifting %s to %s (marginal ROAS %sx) captures an estimated +%s in additional reported return (assumed curve) at the same total spend, relative to your current channel mix scaled to that spend.',
                    $topSource['display_name'],
                    $topSource['marginal_roas_current'],
                    number_format($shiftAmount, 0),
                    $topDest['display_name'],
                    $topDest['marginal_roas_current'],
                    number_format($revenueGain, 0)
                ),
                'projected_revenue_lift' => round($revenueGain, 2),
            ];
        }

        foreach ($allocations as $alloc) {
            if ($alloc['action'] === 'SCALE_UP') {
                $recommendations[] = [
                    'type' => 'CHANNEL_EXPANSION',
                    'severity' => 'OPPORTUNITY',
                    'title' => sprintf('Scenario: move %s spend by %s%% of its same-spend baseline (%s)', $alloc['display_name'], abs($alloc['delta_vs_baseline_percentage']), number_format(abs($alloc['delta_vs_baseline']), 0)),
                    'description' => sprintf(
                        '%s has a higher marginal ROAS under the assumed curve (%sx vs %sx at the optimum). Under that assumption, raising spend to %s equalises marginal ROAS across channels.',
                        $alloc['display_name'],
                        $alloc['marginal_roas_current'],
                        $alloc['marginal_roas_optimal'],
                        number_format($alloc['optimal_spend'], 0)
                    ),
                    'projected_revenue_lift' => $alloc['lift_vs_baseline'],
                ];
            } elseif ($alloc['action'] === 'SCALE_DOWN') {
                $recommendations[] = [
                    'type' => 'SATURATION_DEFENSE',
                    'severity' => 'EFFICIENCY_DRAIN',
                    'title' => sprintf('Scenario: hold %s at %s (%s%% versus its same-spend baseline)', $alloc['display_name'], number_format($alloc['optimal_spend'], 0), abs($alloc['delta_vs_baseline_percentage'])),
                    'description' => sprintf(
                        'Every marginal dollar spent beyond %s on %s yields only %sx return, pulling down overall blended portfolio ROAS. Rebalance towards under-saturated channels.',
                        number_format($alloc['optimal_spend'], 0),
                        $alloc['display_name'],
                        $alloc['marginal_roas_current']
                    ),
                    'projected_revenue_lift' => $alloc['lift_vs_baseline'],
                ];
            }
        }

        return $recommendations;
    }

    private function generateFrontierCurve(float $baseBudget, array $channelParams): array
    {
        $points = [];
        $steps = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5];

        foreach ($steps as $multiplier) {
            $budget = $baseBudget * $multiplier;
            $sMin = 0.0;
            $sMax = 0.0;
            foreach ($channelParams as $pp) {
                $sMin += $pp->minSpend;
                $sMax += $pp->maxSpend;
            }
            if ($budget < $sMin - self::EPSILON || $budget > $sMax + self::EPSILON) {
                continue; // infeasible under the stated limits: no number is invented
            }
            $lam = $this->solveEquilibriumLambda($budget, $channelParams);
            $totalRev = 0.0;

            foreach ($channelParams as $param) {
                /** @var ChannelParameterDto $param */
                $s = $param->spendForMarginalReturn($lam);
                $totalRev += $param->calculateRevenue($s);
            }

            $points[] = [
                'budget' => round($budget, 0),
                'expected_revenue' => round($totalRev, 0),
                'blended_roas' => $budget > 0 ? round($totalRev / $budget, 2) : 0.0,
                'is_current' => $multiplier === 1.0,
            ];
        }

        return $points;
    }

    private function generateSensitivityCurves(array $channelParams, float $totalBudget): array
    {
        $curves = [];
        $spendSteps = [0.1, 0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0];

        foreach ($channelParams as $param) {
            /** @var ChannelParameterDto $param */
            $dataPoints = [];
            $baseSpend = max(100.0, $param->currentSpend);

            foreach ($spendSteps as $step) {
                $spend = round($baseSpend * $step, 0);
                $rev = round($param->calculateRevenue($spend), 0);
                $marginal = round($param->marginalReturn($spend), 3);

                $dataPoints[] = [
                    'spend' => $spend,
                    'revenue' => $rev,
                    'roas' => $spend > 0 ? round($rev / $spend, 2) : 0.0,
                    'marginal_roas' => $marginal,
                ];
            }

            $curves[$param->channel] = [
                'channel' => $param->channel,
                'display_name' => $param->displayName,
                'curve' => $dataPoints,
            ];
        }

        return $curves;
    }
}
