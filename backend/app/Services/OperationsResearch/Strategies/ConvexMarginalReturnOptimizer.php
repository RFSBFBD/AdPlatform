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
        if (empty($channelParams) || $totalBudget <= 0) {
            return new OptimizationResultDto(
                totalBudget: $totalBudget,
                currentTotalRevenue: 0,
                currentBlendedRoas: 0,
                optimalTotalRevenue: 0,
                optimalBlendedRoas: 0,
                revenueGainDelta: 0,
                roasGainDelta: 0,
                equilibriumLambda: 0,
                channelAllocations: [],
                recommendations: [],
                frontierCurve: [],
                sensitivityPoints: []
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

        // 3. Compute optimal allocation per channel at lambda
        $channelAllocations = [];
        $optimalTotalRevenue = 0.0;
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
                'delta_spend' => round($deltaSpend, 2),
                'delta_spend_percentage' => $param->currentSpend > 0 ? round(($deltaSpend / $param->currentSpend) * 100, 1) : 0.0,
                'marginal_roas_current' => round($marginalAtCurrent, 3),
                'marginal_roas_optimal' => round($marginalAtOptimal, 3),
                'action' => $deltaSpend > 25 ? 'SCALE_UP' : ($deltaSpend < -25 ? 'SCALE_DOWN' : 'MAINTAIN'),
            ];
        }

        $optimalBlendedRoas = $totalOptimalSpend > 0 ? ($optimalTotalRevenue / $totalOptimalSpend) : 0.0;
        $revenueGainDelta = max(0.0, $optimalTotalRevenue - $currentTotalRevenue);
        $roasGainDelta = max(0.0, $optimalBlendedRoas - $currentBlendedRoas);

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
            sensitivityPoints: $sensitivityPoints
        );
    }

    /**
     * Bisection search for lambda such that sum(S_i(lambda)) == totalBudget
     */
    private function solveEquilibriumLambda(float $totalBudget, array $channelParams): float
    {
        // Determine search bounds for lambda
        $low = 0.0001;
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

            if (abs($diff) <= self::EPSILON || ($high - $low) < 0.00001) {
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

    private function generateRecommendations(array $allocations, float $revenueGain): array
    {
        $recommendations = [];

        // Identify scale down candidates
        $scaleDown = array_filter($allocations, fn($a) => $a['action'] === 'SCALE_DOWN');
        // Identify scale up candidates
        $scaleUp = array_filter($allocations, fn($a) => $a['action'] === 'SCALE_UP');

        usort($scaleDown, fn($a, $b) => $a['delta_spend'] <=> $b['delta_spend']); // Most negative first
        usort($scaleUp, fn($a, $b) => $b['delta_spend'] <=> $a['delta_spend']);   // Most positive first

        if (!empty($scaleDown) && !empty($scaleUp)) {
            $topSource = $scaleDown[0];
            $topDest = $scaleUp[0];
            $shiftAmount = min(abs($topSource['delta_spend']), $topDest['delta_spend']);

            $recommendations[] = [
                'type' => 'BUDGET_REALLOCATION',
                'severity' => 'HIGH_IMPACT',
                'title' => sprintf(
                    'Reallocate $%s from %s to %s',
                    number_format($shiftAmount, 0),
                    $topSource['display_name'],
                    $topDest['display_name']
                ),
                'description' => sprintf(
                    '%s has hit diminishing returns (marginal ROAS %sx). Shifting $%s to %s (marginal ROAS %sx) captures an estimated +$%s in additional gross return without increasing total expenditure.',
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
                    'title' => sprintf('Scale %s spend by %s%% ($%s)', $alloc['display_name'], abs($alloc['delta_spend_percentage']), number_format(abs($alloc['delta_spend']), 0)),
                    'description' => sprintf(
                        '%s is currently operating in its high-efficiency convex zone (marginal ROAS %sx > optimal threshold %sx). Increasing budget to $%s unlocks maximal yield.',
                        $alloc['display_name'],
                        $alloc['marginal_roas_current'],
                        $alloc['marginal_roas_optimal'],
                        number_format($alloc['optimal_spend'], 0)
                    ),
                    'projected_revenue_lift' => round($alloc['optimal_revenue'] - $alloc['current_revenue'], 2),
                ];
            } elseif ($alloc['action'] === 'SCALE_DOWN') {
                $recommendations[] = [
                    'type' => 'SATURATION_DEFENSE',
                    'severity' => 'EFFICIENCY_DRAIN',
                    'title' => sprintf('Cap %s spend at $%s (-%s%%)', $alloc['display_name'], number_format($alloc['optimal_spend'], 0), abs($alloc['delta_spend_percentage'])),
                    'description' => sprintf(
                        'Every marginal dollar spent beyond $%s on %s yields only %sx return, pulling down overall blended portfolio ROAS. Rebalance towards under-saturated channels.',
                        number_format($alloc['optimal_spend'], 0),
                        $alloc['display_name'],
                        $alloc['marginal_roas_current']
                    ),
                    'projected_revenue_lift' => 0.0,
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
