<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DataCoverageTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $e): User
    {
        return User::firstOrCreate(['email' => $e], ['name' => 'U', 'password' => bcrypt('password123'), 'status' => 'active']);
    }

    private function acct(User $u, string $p, string $ext, string $cur = 'USD'): ConnectedAdAccount
    {
        return ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => $p, 'external_account_id' => $ext, 'account_name' => "$p acct", 'currency' => $cur, 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
    }

    private function row(ConnectedAdAccount $a, string $date, float $spend, float $val, string $cur = 'USD'): void
    {
        PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => $a->provider, 'date' => $date, 'spend' => $spend, 'impressions' => 100, 'clicks' => 5, 'conversions' => 1, 'conversion_value' => $val, 'currency' => $cur]);
    }

    private function cov(User $u): array
    {
        $r = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/data-coverage');
        $r->assertOk();
        return collect($r->json('data.platforms'))->keyBy('provider')->all();
    }

    public function test_statuses_not_connected_no_data_and_available(): void
    {
        $u = $this->user('cov1@example.com');
        $meta = $this->acct($u, 'meta', 'act_1');
        $this->acct($u, 'google', 'g1');
        $d = Carbon::now()->subDays(2)->format('Y-m-d');
        $this->row($meta, $d, 10, 40);
        $p = $this->cov($u);
        $this->assertSame('data_available', $p['meta']['status']);
        $this->assertSame('connected_no_data', $p['google']['status']);
        $this->assertSame('not_connected', $p['tiktok']['status']);
        $this->assertSame('not_connected', $p['snapchat']['status']);
        $this->assertSame(10.0, (float) $p['meta']['totals_by_currency']['USD']['spend']);
        $this->assertTrue($p['meta']['has_conversion_value']);
        $this->assertNull($p['tiktok']['first_date']);
    }

    public function test_snapchat_without_purchase_value_reports_gap_and_never_fills(): void
    {
        $u = $this->user('cov2@example.com');
        $s = $this->acct($u, 'snapchat', 'sn1', 'EUR');
        $this->row($s, Carbon::now()->subDay()->format('Y-m-d'), 7, 0, 'EUR');
        $p = $this->cov($u)['snapchat'];
        $this->assertFalse($p['has_conversion_value']);
        $this->assertFalse($p['has_campaign_level_rows']);
        $this->assertArrayHasKey('EUR', $p['totals_by_currency']);
        $this->assertArrayNotHasKey('USD', $p['totals_by_currency']);
        $this->assertStringContainsString('left out of the OR model', implode(' ', $p['gaps']));
        $this->assertStringContainsString('not link clicks', implode(' ', $p['gaps']));
    }

    public function test_other_users_data_is_never_visible(): void
    {
        $a = $this->user('cov3a@example.com');
        $b = $this->user('cov3b@example.com');
        $this->row($this->acct($b, 'tiktok', 'tt1'), Carbon::now()->subDay()->format('Y-m-d'), 99, 99);
        $this->assertSame('not_connected', $this->cov($a)['tiktok']['status']);
    }

    public function test_failed_last_sync_is_surfaced_and_old_rows_outside_window_ignored(): void
    {
        $u = $this->user('cov4@example.com');
        $g = $this->acct($u, 'google', 'g9');
        $this->row($g, Carbon::now()->subDays(90)->format('Y-m-d'), 50, 50);
        SyncRun::create(['connected_ad_account_id' => $g->id, 'sync_type' => 'manual', 'status' => 'failed', 'error_message' => 'token_expired']);
        $p = $this->cov($u)['google'];
        $this->assertSame('connected_no_data', $p['status']);
        $this->assertSame('failed', $p['accounts'][0]['last_sync_status']);
        $this->assertSame('token_expired', $p['accounts'][0]['last_sync_error']);
    }
}
