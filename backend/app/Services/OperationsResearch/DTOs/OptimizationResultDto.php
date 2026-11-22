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
        public readonly string $mathematicalModel = 'Concave exponential response curve (assumed, not fitted); numerical solution of the equal-marginal-return (KKT) condition',
        public readonly string $status = 'ok', // ok | insufficient_data | infeasible
        public readonly ?string $statusMessage = null,
        public readonly array $warnings = [],
        public readonly array $dataSources = [],
        public readonly array $gates = [],
        public readonly array $certificate = [],
        public readonly array $comparison = []
    ) {}

    public function withGates(array $gates): self
    {
        return new self(
            $this->totalBudget, $this->currentTotalRevenue, $this->currentBlendedRoas, $this->optimalTotalRevenue,
            $this->optimalBlendedRoas, $this->revenueGainDelta, $this->roasGainDelta, $this->equilibriumLambda,
            $this->channelAllocations, $this->recommendations, $this->frontierCurve, $this->sensitivityPoints,
            $this->mathematicalModel, $this->status, $this->statusMessage, $this->warnings, $this->dataSources, $gates, $this->certificate, $this->comparison
        );
    }

    /**
     * A result that carries no numbers and no recommendations: used when the engine must abstain.
     */
    public static function abstain(string $status, string $message, float $budget = 0.0, array $warnings = [], array $dataSources = []): self
    {
        return new self(
            totalBudget: $budget,
            currentTotalRevenue: 0.0,
            currentBlendedRoas: 0.0,
            optimalTotalRevenue: 0.0,
            optimalBlendedRoas: 0.0,
            revenueGainDelta: 0.0,
            roasGainDelta: 0.0,
            equilibriumLambda: 0.0,
            channelAllocations: [],
            recommendations: [],
            frontierCurve: [],
            sensitivityPoints: [],
            status: $status,
            statusMessage: $message,
            warnings: $warnings,
            dataSources: $dataSources,
        );
    }

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
            'revenue_gain_percentage' => ($this->comparison['baseline_revenue_same_spend'] ?? 0) > 0
                ? round(($this->revenueGainDelta / $this->comparison['baseline_revenue_same_spend']) * 100, 2)
                : 0.0,
            'equilibrium_lambda' => round($this->equilibriumLambda, 4),
            'channel_allocations' => $this->channelAllocations,
            'recommendations' => $this->recommendations,
            'frontier_curve' => $this->frontierCurve,
            'sensitivity_points' => $this->sensitivityPoints,
            'mathematical_model' => $this->mathematicalModel,
            'status' => $this->status,
            'status_message' => $this->statusMessage,
            'warnings' => $this->warnings,
            'data_sources' => $this->dataSources,
            'gates' => $this->gates,
            'solver_certificate' => $this->certificate,
            'comparison' => $this->comparison,
        ];
    }
}
