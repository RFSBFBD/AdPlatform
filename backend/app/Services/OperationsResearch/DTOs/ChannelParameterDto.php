<?php

namespace App\Services\OperationsResearch\DTOs;

final class ChannelParameterDto
{
    public function __construct(
        public readonly string $channel,
        public readonly string $displayName,
        public readonly float $currentSpend,
        public readonly float $currentRevenue,
        public readonly float $currentRoas,
        public readonly float $alpha, // Saturation maximum revenue asymptote
        public readonly float $beta,  // Diminishing returns rate parameter
        public readonly float $minSpend = 0.0,
        public readonly float $maxSpend = 100000.0,
    ) {}

    /**
     * Compute deterministic expected revenue R(S) = alpha * (1 - exp(-beta * S))
     */
    public function calculateRevenue(float $spend): float
    {
        if ($spend <= 0.0) {
            return 0.0;
        }
        return $this->alpha * (1.0 - exp(-$this->beta * $spend));
    }

    /**
     * Compute marginal return (first derivative dR/dS) = alpha * beta * exp(-beta * S)
     */
    public function marginalReturn(float $spend): float
    {
        if ($spend <= 0.0) {
            return $this->alpha * $this->beta;
        }
        return $this->alpha * $this->beta * exp(-$this->beta * $spend);
    }

    /**
     * Solve inverse for spend given marginal return lambda:
     * S = (1 / beta) * ln((alpha * beta) / lambda)
     */
    public function spendForMarginalReturn(float $lambda): float
    {
        $maxMarginal = $this->alpha * $this->beta;
        if ($lambda >= $maxMarginal) {
            return $this->minSpend;
        }
        if ($lambda <= 1e-12) {
            return $this->maxSpend;
        }

        $spend = (1.0 / $this->beta) * log($maxMarginal / $lambda);
        return max($this->minSpend, min($this->maxSpend, $spend));
    }

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'display_name' => $this->displayName,
            'current_spend' => round($this->currentSpend, 2),
            'current_revenue' => round($this->currentRevenue, 2),
            'current_roas' => round($this->currentRoas, 2),
            'alpha' => round($this->alpha, 4),
            'beta' => round($this->beta, 6),
            'min_spend' => $this->minSpend,
            'max_spend' => $this->maxSpend,
        ];
    }
}
