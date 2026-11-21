<?php

namespace App\Services\OperationsResearch\DTOs;

final class OptimizationResultDto
{
    /**
     * @param float $totalBudget
     * @param float $currentTotalRevenue
     * @param float $currentBlendedRoas
     * @param float $optimalTotalRevenue
     * @param float $optimalBlendedRoas
     * @param float $revenueGainDelta
     * @param float $roasGainDelta
     * @param float $equilibriumLambda Marginal ROAS threshold at optimality
     * @param array $channelAllocations Array of per-channel allocations and shifts
     * @param array $recommendations Mathematical shift directives
     * @param array $frontierCurve Data points (budget vs revenue) for the convex graph
     * @param array $sensitivityPoints Sensitivity analysis curves for each channel
     */
    public function __construct(
        public readonly float $totalBudget,
        public readonly float $currentTotalRevenue,
        public readonly float $currentBlendedRoas,
        public readonly float $optimalTotalRevenue,
        public readonly float $optimalBlendedRoas,
        public readonly float $revenueGainDelta,
        public readonly float $roasGainDelta,
        public readonly float $equilibriumLambda,
        public readonly array $channelAllocations,
        public readonly array $recommendations,
        public readonly array $frontierCurve,
        public readonly array $sensitivityPoints,
        public readonly string $mathematicalModel = 'Convex Diminishing Marginal Returns (KKT Equilibrium)'
    ) {}

    public function toArray(): array
    {
        return [
            'total_budget' => round($this->totalBudget, 2),
            'current_total_revenue' => round($this->currentTotalRevenue, 2),
            'current_blended_roas' => round($this->currentBlendedRoas, 2),
            'optimal_total_revenue' => round($this->optimalTotalRevenue, 2),
            'optimal_blended_roas' => round($this->optimalBlendedRoas, 2),
            'revenue_gain_delta' => round($this->revenueGainDelta, 2),
            'roas_gain_delta' => round($this->roasGainDelta, 2),
            'revenue_gain_percentage' => $this->currentTotalRevenue > 0 
                ? round(($this->revenueGainDelta / $this->currentTotalRevenue) * 100, 2) 
                : 0.0,
            'equilibrium_lambda' => round($this->equilibriumLambda, 4),
            'channel_allocations' => $this->channelAllocations,
            'recommendations' => $this->recommendations,
            'frontier_curve' => $this->frontierCurve,
            'sensitivity_points' => $this->sensitivityPoints,
            'mathematical_model' => $this->mathematicalModel,
        ];
    }
}
