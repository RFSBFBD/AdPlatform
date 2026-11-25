<?php
namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\TaboolaAdsAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaboolaAdapterTest extends TestCase
{
    private function adapter(): TaboolaAdsAdapter
    {
        return new TaboolaAdsAdapter(new ProviderDeveloperConfig(['app_id' => 'fixture-id', 'app_secret' => 'fixture-secret', 'is_enabled' => true]));
    }
    public function test_documented_client_grant_and_account_mapping(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fixture-access', 'expires_in' => 43200]),
            '*/allowed-accounts' => Http::response(['results' => [['account_id' => 'fixture-advertiser', 'name' => 'Fixture', 'currency' => 'EUR', 'time_zone_name' => 'Europe/Paris']]]),
        ]);
        $a = $this->adapter();
        $this->assertNull($a->issueToken()['refresh_token']);
        $rows = $a->discoverAccounts('fixture-access');
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame('Europe/Paris', $rows[0]['timezone']);
        Http::assertSent(fn($r) => str_ends_with($r->url(), '/oauth/token') && $r['grant_type'] === 'client_credentials');
    }
    public function test_account_day_report_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/reports/campaign-summary/dimensions/day*' => Http::response(['results' => [[
            'date' => '2026-11-01', 'spent' => 10.5, 'impressions' => 1000, 'clicks' => 23, 'cpa_actions_num' => 2, 'conversions_value' => 30, 'currency' => 'EUR',
        ]]])]);
        $account = new ConnectedAdAccount(['external_account_id' => 'fixture', 'access_token' => 'fixture-token', 'currency' => 'EUR']);
        $r = $this->adapter()->fetchInsights($account, '2026-11-01', '2026-11-01');
        $this->assertSame(10.5, $r['metrics'][0]['spend']);
        $this->assertSame(30.0, $r['metrics'][0]['conversion_value']);
        $this->assertNull($r['metrics'][0]['external_campaign_id']);
    }
    public function test_missing_conversion_value_abstains_instead_of_becoming_zero(): void
    {
        Http::fake(['*' => Http::response(['results' => [['date' => '2026-11-01', 'spent' => 10, 'impressions' => 100, 'clicks' => 2]]])]);
        $this->expectException(\RuntimeException::class);
        $this->adapter()->fetchInsights(new ConnectedAdAccount(['external_account_id' => 'f', 'access_token' => 't']), '2026-11-01', '2026-11-01');
    }
    public function test_browser_oauth_is_not_fabricated(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->getAuthorizationUrl('state', 'https://example.test/cb');
    }

    private function cdAccount(): ConnectedAdAccount
    {
        return new ConnectedAdAccount(['external_account_id' => 'fixture', 'access_token' => 'fixture-token', 'currency' => 'EUR']);
    }
    private function cdRow(array $o = []): array
    {
        return array_merge(['campaign' => '111', 'campaign_name' => 'C1', 'date' => '2026-11-01 00:00:00.0', 'spent' => 10.5,
            'impressions' => 1000, 'clicks' => 23, 'cpa_actions_num' => 2, 'conversions_value' => 30, 'currency' => 'EUR'], $o);
    }
    public function test_campaign_day_rows_map_with_campaign_ids(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/reports/campaign-summary/dimensions/campaign_day_breakdown*' => Http::response(['results' => [
            $this->cdRow(), $this->cdRow(['campaign' => '222', 'spent' => 4]), $this->cdRow(['date' => '2026-11-02 00:00:00.0']),
        ]])]);
        $r = $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), '2026-11-01', '2026-11-02');
        $this->assertCount(3, $r['metrics']);
        $this->assertSame('111', $r['metrics'][0]['external_campaign_id']);
        $this->assertSame('2026-11-01', $r['metrics'][0]['date']);
        $this->assertSame(4.0, $r['metrics'][1]['spend']);
        Http::assertSent(fn($q) => str_contains($q->url(), '/fixture/reports/campaign-summary/dimensions/campaign_day_breakdown')
            && $q['start_date'] === '2026-11-01' && $q['end_date'] === '2026-11-02');
    }
    public function test_campaign_day_malformed_input_refuses_whole_result(): void
    {
        $bad = [
            'missing campaign' => $this->cdRow(['campaign' => null]),
            'blank campaign' => $this->cdRow(['campaign' => ' ']),
            'array campaign' => $this->cdRow(['campaign' => ['x']]),
            'missing metric' => array_diff_key($this->cdRow(), ['conversions_value' => 1]),
            'bad date' => $this->cdRow(['date' => 'yesterday']),
            'outside range' => $this->cdRow(['date' => '2026-12-01 00:00:00.0']),
            'currency mismatch' => $this->cdRow(['currency' => 'USD']),
            'non numeric spend' => $this->cdRow(['spent' => 'abc']),
        ];
        foreach ($bad as $label => $row) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response(['results' => [$this->cdRow(['campaign' => '9']), $row]])]);
            try {
                $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), '2026-11-01', '2026-11-02');
                $this->fail("Accepted: $label");
            } catch (\RuntimeException $e) {
                $this->assertTrue(true);
            }
        }
    }
    public function test_campaign_day_duplicate_pair_range_and_http_failures_refuse(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response(['results' => [$this->cdRow(), $this->cdRow()]])]);
        try { $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), '2026-11-01', '2026-11-02'); $this->fail('dup'); } catch (\RuntimeException $e) { $this->assertStringContainsString('repeats', $e->getMessage()); }
        foreach ([['2026-11-02', '2026-11-01'], ['2026-1-1', '2026-11-01'], ['2026-11-01', '2999-01-01']] as [$a, $b]) {
            try { $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), $a, $b); $this->fail('range'); } catch (\RuntimeException $e) { $this->assertTrue(true); }
        }
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response('{"results":[{"campaign":"1","spe', 200)]);
        try { $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), '2026-11-01', '2026-11-02'); $this->fail('truncated'); } catch (\RuntimeException $e) { $this->assertTrue(true); }
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response([], 429)]);
        $this->expectException(\App\Exceptions\ProviderRateLimitException::class);
        $this->adapter()->fetchCampaignDayInsights($this->cdAccount(), '2026-11-01', '2026-11-02');
    }
}
