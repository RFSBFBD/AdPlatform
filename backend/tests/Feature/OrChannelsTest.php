<?php
namespace Tests\Feature;

use App\Services\OperationsResearch\AdvertisingOptimizationService as S;
use Tests\TestCase;

/** The solver is N-channel: Snapchat joins as a fourth channel under the same rules. */
class OrChannelsTest extends TestCase
{
    private const TODAY = '2026-11-22';

    private function m($sp, $rv, $cur = 'USD', $days = 30): array
    {
        return ['total_spend' => $sp, 'total_revenue' => $rv, 'days_with_data' => $days, 'latest_date' => '2026-11-21', 'currencies' => [$cur]];
    }
    private function four(): array
    {
        return ['meta' => $this->m(5000, 15000), 'google' => $this->m(4000, 18000), 'tiktok' => $this->m(2000, 5000), 'snapchat' => $this->m(1500, 4200)];
    }
    private function run1(array $met, array $o = [])
    {
        return (new S())->optimizeFromMetrics($met, array_merge(['budget' => 12500.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5], $o), self::TODAY);
    }

    public function test_four_channels_allocate_exactly_the_budget(): void
    {
        $r = $this->run1($this->four());
        $this->assertSame('ok', $r->status);
        $this->assertCount(4, $r->channelAllocations);
        $sum = array_sum(array_column($r->channelAllocations, 'optimal_spend'));
        $this->assertEqualsWithDelta(12500.0, $sum, 0.02);
        $this->assertContains('snapchat', array_column($r->channelAllocations, 'channel'));
        $this->assertTrue($r->certificate['within_bounds'] ?? true);
    }

    public function test_snapchat_in_another_currency_refuses_instead_of_converting(): void
    {
        $met = $this->four();
        $met['snapchat'] = $this->m(1500, 4200, 'EUR');
        $r = $this->run1($met);
        $this->assertSame('mixed_currency', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_snapchat_without_purchase_value_is_excluded_with_reason(): void
    {
        $met = $this->four();
        $met['snapchat'] = $this->m(1500, 0);
        $r = $this->run1($met, ['budget' => 11000.0]);
        $this->assertSame('ok', $r->status);
        $this->assertCount(3, $r->channelAllocations);
        $this->assertStringContainsString('snapchat excluded', implode(' ', $r->warnings));
    }

    public function test_snapchat_limit_is_respected(): void
    {
        $r = $this->run1($this->four(), ['channel_limits_horizon' => 'per_30_days', 'channel_limits' => ['snapchat' => ['min' => 3000.0, 'max' => 3500.0]]]);
        $this->assertSame('ok', $r->status);
        foreach ($r->channelAllocations as $a) {
            if ($a['channel'] === 'snapchat') {
                $this->assertGreaterThanOrEqual(3000.0 - 0.01, $a['optimal_spend']);
                $this->assertLessThanOrEqual(3500.0 + 0.01, $a['optimal_spend']);
            }
        }
    }
}
