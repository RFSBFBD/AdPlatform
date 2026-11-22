<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\OperationsResearch\AdvertisingOptimizationService as S;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Snap metrics are final 48h after the end of the day in the ACCOUNT timezone (measurement docs). */
class SnapchatFinalityTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setup2(string $tz): array
    {
        $u = User::create(['name' => 'F', 'email' => 'fin_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'snapchat', 'external_account_id' => 's' . uniqid(), 'account_name' => 'S', 'currency' => 'USD', 'timezone' => $tz, 'access_token' => 't', 'status' => 'active']);
        return [$u, $a];
    }

    public function test_cap_is_account_local_today_minus_three_days(): void
    {
        // 2026-11-22 23:30 UTC is already 2026-11-23 in Pacific/Auckland, still 2026-11-22 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:30:00', 'UTC'));
        [, $utc] = $this->setup2('UTC');
        [, $nz] = $this->setup2('Pacific/Auckland');
        $caps = S::snapchatFinalCaps([$utc, $nz]);
        $this->assertSame('2026-11-19', $caps[$utc->id]);
        $this->assertSame('2026-11-20', $caps[$nz->id]);
    }

    public function test_missing_or_invalid_timezone_has_no_usable_day(): void
    {
        [, $a] = $this->setup2('UTC');
        $a->timezone = null;
        $this->assertArrayNotHasKey($a->id, S::snapchatFinalCaps([$a]));
        $a->timezone = 'Not/AZone';
        $this->assertArrayNotHasKey($a->id, S::snapchatFinalCaps([$a]));
    }

    public function test_or_window_and_totals_ignore_non_final_snapchat_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'UTC'));
        [$u, $a] = $this->setup2('UTC');
        // Final up to 2026-11-19. Yesterday (11-21) and 11-20 are not final.
        foreach (['2026-11-19' => 10, '2026-11-20' => 99, '2026-11-21' => 99] as $d => $sp) {
            PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => 'snapchat', 'date' => $d, 'spend' => $sp, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 30, 'currency' => 'USD']);
        }
        $r = (new S())->optimizeForUser($u->id, []);
        $win = $r->gates['window'] ?? null;
        $this->assertNotNull($win);
        $this->assertSame('2026-11-19', $win['end']);
    }

    public function test_only_non_final_days_means_no_window_and_no_numbers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'UTC'));
        [$u, $a] = $this->setup2('UTC');
        PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => 'snapchat', 'date' => '2026-11-21', 'spend' => 50, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 90, 'currency' => 'USD']);
        $r = (new S())->optimizeForUser($u->id, ['budget' => 1000, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5]);
        $this->assertNotSame('ok', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_other_providers_are_not_delayed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'UTC'));
        $u = User::create(['name' => 'F', 'email' => 'fin_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);
        $m = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => 'm' . uniqid(), 'account_name' => 'M', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
        PerformanceMetric::create(['connected_ad_account_id' => $m->id, 'provider' => 'meta', 'date' => '2026-11-21', 'spend' => 5, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 9, 'currency' => 'USD']);
        $r = (new S())->optimizeForUser($u->id, []);
        $this->assertSame('2026-11-21', $r->gates['window']['end']);
    }
}
