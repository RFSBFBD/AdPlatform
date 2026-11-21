<?php

namespace App\Services\OperationsResearch\Contracts;

use App\Services\OperationsResearch\DTOs\OptimizationResultDto;

interface OptimizationStrategyInterface
{
    /**
     * Solve the cross-channel budget allocation optimization problem.
     *
     * @param float $totalBudget
     * @param array $channelParams Array of ChannelParameterDto instances
     * @param array $constraints Additional boundary or ratio constraints
     * @return OptimizationResultDto
     */
    public function optimize(float $totalBudget, array $channelParams, array $constraints = []): OptimizationResultDto;
}
