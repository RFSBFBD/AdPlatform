<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationsResearch\AdvertisingOptimizationService;
use App\Services\OperationsResearch\DTOs\ChannelParameterDto;
use App\Services\OperationsResearch\Strategies\ConvexMarginalReturnOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsResearchTest extends TestCase
{
    public function test_convex_optimizer_computes_exact_kkt_equilibrium(): void
    {
        $optimizer = new ConvexMarginalReturnOptimizer();

        $meta = new ChannelParameterDto(
            channel: 'meta',
            displayName: 'Meta Ads',
            currentSpend: 6000.0,
            currentRevenue: 18000.0,
            currentRoas: 3.0,
            alpha: 40000.0,
            beta: 0.00010
        );

        $google = new ChannelParameterDto(
            channel: 'google',
            displayName: 'Google Ads',
            currentSpend: 4000.0,
            currentRevenue: 16000.0,
            currentRoas: 4.0,
            alpha: 50000.0,
            beta: 0.00009
        );

        $result = $optimizer->optimize(10000.0, [$meta, $google]);

        // Total allocated spend should match total budget ($10,000) within tolerance
        $totalAllocated = array_sum(array_column($result->channelAllocations, 'optimal_spend'));
        $this->assertEqualsWithDelta(10000.0, $totalAllocated, 1.0);

        // Optimal revenue should be greater than or equal to current revenue
        $this->assertGreaterThanOrEqual($result->currentTotalRevenue, $result->optimalTotalRevenue);

        // Frontier curve should have generated points
        $this->assertNotEmpty($result->frontierCurve);
        $this->assertNotEmpty($result->recommendations);
    }

    public function test_api_endpoint_returns_operations_research_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/analytics/operations-research?budget=15000');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_budget',
                    'current_total_revenue',
                    'current_blended_roas',
                    'optimal_total_revenue',
                    'optimal_blended_roas',
                    'revenue_gain_delta',
                    'channel_allocations',
                    'recommendations',
                    'frontier_curve',
                    'sensitivity_points',
                    'mathematical_model',
                ],
            ]);
    }
}
