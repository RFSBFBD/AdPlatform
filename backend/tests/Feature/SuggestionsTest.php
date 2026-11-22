<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\Advertising\SuggestionBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SuggestionsTest extends TestCase
{
    use DatabaseTransactions;

    private function plat(string $p, string $status, array $o = []): array
    {
        return array_merge(['provider' => $p, 'status' => $status, 'accounts' => [], 'has_conversion_value' => false, 'has_campaign_level_rows' => false, 'gaps' => []], $o);
    }

    public function test_every_suggestion_is_read_only_and_cites_evidence(): void
    {
        $cov = ['platforms' => [
            $this->plat('meta', 'data_available', ['has_conversion_value' => true]),
            $this->plat('google', 'connected_no_data', ['accounts' => [['name' => 'G', 'last_sync_status' => 'failed', 'last_sync_error' => 'token_expired']]]),
            $this->plat('tiktok', 'not_connected'),
            $this->plat('snapchat', 'data_available'),
        ]];
        $s = (new SuggestionBuilder())->build($cov, ['status' => 'constraints_required', 'status_message' => 'Budget required.']);
        $this->assertNotEmpty($s);
        foreach ($s as $i) {
            $this->assertSame('suggestion_only', $i['mode']);
            $this->assertNotEmpty($i['evidence']);
            $this->assertContains($i['evidence'][0]['source'], ['pulled_data', 'or_result']);
        }
        $titles = implode('|', array_column($s, 'title'));
        $this->assertStringContainsString('Connect TikTok Ads', $titles);
        $this->assertStringContainsString('failed sync for Google Ads', $titles);
        $this->assertStringContainsString('Snapchat Ads has no purchase value', $titles);
        $this->assertStringContainsString('No budget scenario was produced', $titles);
        // Abstained OR means no budget suggestion at all.
        $this->assertNotContains('budget', array_column($s, 'kind'));
    }

    public function test_budget_items_only_relay_or_numbers_with_scenario_label(): void
    {
        $or = ['status' => 'ok', 'warnings' => ['Snapchat Ads excluded: no purchase value.'], 'channel_allocations' => [
            ['channel' => 'meta', 'display_name' => 'Meta Ads', 'action' => 'SCALE_UP', 'optimal_spend' => 6000, 'baseline_spend_same_budget' => 5000, 'marginal_roas_current' => 3.1, 'marginal_roas_optimal' => 2.4],
            ['channel' => 'google', 'display_name' => 'Google Ads', 'action' => 'MAINTAIN', 'optimal_spend' => 4000, 'baseline_spend_same_budget' => 4000, 'marginal_roas_current' => 2.4, 'marginal_roas_optimal' => 2.4],
        ]];
        $s = (new SuggestionBuilder())->build(['platforms' => []], $or);
        $b = array_values(array_filter($s, fn($i) => $i['kind'] === 'budget'));
        $this->assertCount(2, $b); // meta scale-up + excluded warning, nothing invented for MAINTAIN
        $this->assertSame('scenario', $b[0]['severity']);
        $this->assertStringContainsString('assumed response curve', $b[0]['text']);
        $this->assertStringContainsString('6000', $b[0]['text']);
        $this->assertSame('6000', $b[0]['evidence'][0]['value']);
    }

    public function test_endpoint_without_connected_accounts_makes_no_budget_suggestion_and_no_writes(): void
    {
        $u = User::firstOrCreate(['email' => 'sug1@example.com'], ['name' => 'U', 'password' => bcrypt('password123'), 'status' => 'active']);
        $before = PerformanceMetric::count();
        $r = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions');
        $r->assertOk();
        $items = $r->json('data.suggestions');
        $this->assertNotContains('budget', array_column($items, 'kind'));
        $this->assertStringContainsString('Nothing is applied', $r->json('data.note'));
        $this->assertSame($before, PerformanceMetric::count());
    }

    public function test_endpoint_surfaces_snapchat_without_value_and_reuses_or_validation(): void
    {
        $u = User::firstOrCreate(['email' => 'sug2@example.com'], ['name' => 'U', 'password' => bcrypt('password123'), 'status' => 'active']);
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'snapchat', 'external_account_id' => 's1', 'account_name' => 'Snap', 'currency' => 'EUR', 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
        PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => 'snapchat', 'date' => Carbon::now()->subDay()->format('Y-m-d'), 'spend' => 5, 'impressions' => 10, 'clicks' => 1, 'conversions' => 0, 'conversion_value' => 0, 'currency' => 'EUR']);
        $r = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions');
        $r->assertOk();
        $titles = implode('|', array_column($r->json('data.suggestions'), 'title'));
        $this->assertStringContainsString('Snapchat Ads has no purchase value', $titles);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions?budget=-5')->assertStatus(422);
    }
}
