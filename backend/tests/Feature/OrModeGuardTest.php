<?php
namespace Tests\Feature;

use App\Services\OperationsResearch\AdvertisingOptimizationService as S;
use Tests\TestCase;

/** Mode/floor mixing, zero-spend at_most, and the minimum-exceeds-budget message. */
class OrModeGuardTest extends TestCase
{
    private const TODAY = '2026-11-22';

    private function m($sp, $rv): array
    {
        return ['total_spend' => $sp, 'total_revenue' => $rv, 'days_with_data' => 30, 'latest_date' => '2026-11-21', 'currencies' => ['USD']];
    }
    private function run1(array $o)
    {
        $met = ['meta' => $this->m(5000, 15000), 'google' => $this->m(4000, 18000)];
        return (new S())->optimizeFromMetrics($met, array_merge(['budget' => 9000.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5], $o), self::TODAY);
    }

    public function test_min_marginal_roas_with_exact_is_rejected_not_ignored(): void
    {
        $r = $this->run1(['budget_mode' => 'exact', 'min_marginal_roas' => 1.2]);
        $this->assertSame('constraints_required', $r->status);
        $this->assertSame([], $r->channelAllocations);
        $this->assertStringContainsString('at_most', (string) $r->statusMessage);
    }

    public function test_min_marginal_roas_without_mode_is_rejected(): void
    {
        $r = $this->run1(['min_marginal_roas' => 1.2]);
        $this->assertSame('constraints_required', $r->status);
    }

    public function test_at_most_with_unreachable_floor_is_no_allocation_not_ok(): void
    {
        $r = $this->run1(['budget_mode' => 'at_most', 'min_marginal_roas' => 1000000.0]);
        $this->assertSame('no_allocation', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_at_most_with_reachable_floor_is_still_ok(): void
    {
        $r = $this->run1(['budget_mode' => 'at_most', 'min_marginal_roas' => 1.0]);
        $this->assertSame('ok', $r->status);
    }

    public function test_minimum_above_budget_says_so(): void
    {
        $r = $this->run1(['budget' => 1000.0, 'channel_limits_horizon' => 'per_30_days', 'channel_limits' => ['meta' => ['min' => 5000.0]]]);
        $this->assertSame('infeasible', $r->status);
        $this->assertStringContainsString('minimums add up', (string) $r->statusMessage);
        $this->assertStringContainsString('more than the budget', (string) $r->statusMessage);
    }
}
