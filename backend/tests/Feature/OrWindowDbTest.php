<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\OperationsResearch\AdvertisingOptimizationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Database rows for the common 30-complete-day window: today, yesterday, old data, gaps. */
class OrWindowDbTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleAndPermissionSeeder())->run();
        $this->user = User::create(['name' => 'OR Window', 'email' => 'orwin_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);
        foreach (['meta', 'google'] as $p) {
            $this->acc[$p] = ConnectedAdAccount::create([
                'user_id' => $this->user->id, 'provider' => $p, 'external_account_id' => 'act_' . $p . uniqid(),
                'account_name' => $p, 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'tok', 'status' => 'connected',
            ]);
        }
    }

    /** rows for $provider on days (today - $fromAgo) .. (today - $toAgo) inclusive, skipping $skipAgo */
    private function rows(string $provider, int $fromAgo, int $toAgo, array $skipAgo = []): void
    {
        for ($a = $fromAgo; $a >= $toAgo; $a--) {
            if (in_array($a, $skipAgo, true)) {
                continue;
            }
            PerformanceMetric::create([
                'connected_ad_account_id' => $this->acc[$provider]->id, 'provider' => $provider, 'currency' => 'USD',
                'date' => now()->subDays($a)->toDateString(), 'spend' => $provider === 'meta' ? 150.0 : 130.0,
                'impressions' => 1000, 'clicks' => 50, 'conversions' => 5, 'conversion_value' => $provider === 'meta' ? 450.0 : 600.0,
            ]);
        }
    }

    private function solve(): \App\Services\OperationsResearch\DTOs\OptimizationResultDto
    {
        return (new AdvertisingOptimizationService())->optimizeForUser($this->user->id, ['budget' => 8000.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5]);
    }

    public function test_complete_data_through_yesterday_gives_one_30_day_window_ending_yesterday(): void
    {
        $this->rows('meta', 30, 1);
        $this->rows('google', 30, 1);
        $this->rows('meta', 0, 0); // today's partial row must be ignored
        $r = $this->solve();
        $this->assertSame('ok', $r->status);
        $w = $r->gates['window'];
        $this->assertSame(now()->subDay()->toDateString(), $w['end']);
        $this->assertSame(now()->subDays(30)->toDateString(), $w['start']);
        $this->assertEqualsWithDelta(30 * 150.0 + 30 * 130.0, $r->comparison['observed_spend'], 0.01);
    }

    public function test_channel_synced_two_days_ago_is_in_a_common_window_not_excluded(): void
    {
        $this->rows('meta', 31, 1);
        $this->rows('google', 32, 2); // allowed by the staleness gate (3 days)
        $r = $this->solve();
        $this->assertSame('ok', $r->status);
        $this->assertSame(now()->subDays(2)->toDateString(), $r->gates['window']['end']);
        // meta's newest day (yesterday) is outside the common window and is not used
        $this->assertEqualsWithDelta(30 * 150.0 + 30 * 130.0, $r->comparison['observed_spend'], 0.01);
    }

    public function test_channel_with_a_gap_is_excluded_not_scaled(): void
    {
        $this->rows('meta', 30, 1, [10]);
        $this->rows('google', 30, 1);
        $r = $this->solve();
        $this->assertSame('insufficient_data', $r->status);
        $this->assertStringContainsString('only 29 of 30 days', implode(' ', $r->warnings));
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_old_data_beyond_staleness_gate_leaves_no_window(): void
    {
        $this->rows('meta', 40, 10);
        $this->rows('google', 40, 10);
        $r = $this->solve();
        $this->assertSame('insufficient_data', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_one_stale_channel_does_not_drag_the_window_of_fresh_ones(): void
    {
        $this->rows('meta', 30, 1);
        $this->rows('google', 45, 15); // stale: excluded by the staleness gate, window ends at meta's last day
        $r = $this->solve();
        $this->assertSame('insufficient_data', $r->status); // only one fresh channel remains
        $this->assertSame([], $r->channelAllocations);
    }
}
