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
}
