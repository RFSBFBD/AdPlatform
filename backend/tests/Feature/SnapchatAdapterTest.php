<?php

namespace Tests\Feature;

use App\Exceptions\OAuthTokenExpiredException;
use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use App\Services\Advertising\Adapters\SnapchatAdsAdapter;
use App\Services\Advertising\OAuthManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fake transport only: response shapes follow the official docs (docs/PROVIDER_SOURCES.md).
 * Nothing here proves the adapter works against a real Snapchat account.
 */
class SnapchatAdapterTest extends TestCase
{
    use DatabaseTransactions;

    private function cfg(): ProviderDeveloperConfig
    {
        return new ProviderDeveloperConfig(['provider' => 'snapchat', 'app_id' => 'cid-test', 'app_secret' => 'sec-test-only']);
    }

    private function account(array $over = [], bool $persist = true): ConnectedAdAccount
    {
        $u = User::create(['name' => 'Snap', 'email' => 'snap_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);

        $attrs = array_merge([
            'user_id' => $u->id, 'provider' => 'snapchat', 'external_account_id' => 'snapacc' . uniqid(), 'account_name' => 's',
            'currency' => 'USD', 'timezone' => 'America/Los_Angeles', 'access_token' => 'tok', 'refresh_token' => 'ref', 'status' => 'connected',
        ], $over);

        return $persist ? ConnectedAdAccount::create($attrs) : new ConnectedAdAccount($attrs);
    }

    private function statsJson(array $days): array
    {
        return ['request_status' => 'SUCCESS', 'timeseries_stats' => [[
            'sub_request_status' => 'SUCCESS',
            'timeseries_stat' => ['id' => 'x', 'type' => 'AD_ACCOUNT', 'granularity' => 'DAY', 'timeseries' => $days],
        ]]];
    }

    public function test_authorization_url_uses_documented_endpoint_and_scope(): void
    {
        $url = (new SnapchatAdsAdapter($this->cfg()))->getAuthorizationUrl('st8', 'https://x.example/cb');
        $this->assertStringStartsWith('https://accounts.snapchat.com/login/oauth2/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('cid-test', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('snapchat-marketing-api', $q['scope']);
        $this->assertSame('st8', $q['state']);
        $this->assertSame('https://x.example/cb', $q['redirect_uri']);
    }

    public function test_oauth_manager_knows_snapchat(): void
    {
        $this->assertInstanceOf(SnapchatAdsAdapter::class, (new OAuthManager())->getAdapter('snapchat'));
    }

    public function test_token_exchange_never_assumes_longer_than_returned_lifetime(): void
    {
        Http::fake(['accounts.snapchat.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);
        $t = (new SnapchatAdsAdapter($this->cfg()))->handleOAuthCallback('code1', 'https://x.example/cb');
        $this->assertSame('a', $t['access_token']);
        $this->assertSame('r', $t['refresh_token']);
        $this->assertEqualsWithDelta(now()->addSeconds(3600)->timestamp, $t['expires_at']->timestamp, 5);
        Http::assertSent(fn($r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code1' && $r['redirect_uri'] === 'https://x.example/cb');
    }

    public function test_discover_accounts_flattens_organizations_and_keeps_account_currency_and_timezone(): void
    {
        Http::fake(['adsapi.snapchat.com/v1/me/organizations*' => Http::response(['request_status' => 'SUCCESS', 'organizations' => [
            ['sub_request_status' => 'SUCCESS', 'organization' => ['id' => 'o1', 'ad_accounts' => [
                ['id' => 'a1', 'name' => 'One', 'status' => 'ACTIVE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin'],
                ['id' => 'a2', 'name' => 'Two', 'status' => 'ACTIVE'],
            ]]],
        ]])]);
        $a = (new SnapchatAdsAdapter($this->cfg()))->discoverAccounts('tok');
        $this->assertCount(2, $a);
        $this->assertSame('EUR', $a[0]['currency']);
        $this->assertSame('Europe/Berlin', $a[0]['timezone']);
        $this->assertNull($a[1]['currency'], 'a missing currency must stay missing, not default to USD');
        Http::assertSent(fn($r) => $r->hasHeader('Authorization', 'Bearer tok') && $r['with_ad_accounts'] === 'true');
    }

    public function test_insights_convert_micro_currency_and_use_account_timezone_boundaries(): void
    {
        Http::fake(['adsapi.snapchat.com/*' => Http::response($this->statsJson([
            ['start_time' => '2026-11-10T00:00:00.000-08:00', 'end_time' => '2026-11-11T00:00:00.000-08:00', 'stats' => [
                'impressions' => 1000, 'swipes' => 40, 'spend' => 12500000, 'conversion_purchases' => 3, 'conversion_purchases_value' => 90250000]],
            ['start_time' => '2026-11-11T00:00:00.000-08:00', 'end_time' => '2026-11-12T00:00:00.000-08:00', 'stats' => ['impressions' => 5, 'swipes' => 0, 'spend' => 1000000]],
        ]))]);
        $r = (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($this->account(), '2026-11-10', '2026-11-11');
        $this->assertSame([], $r['metrics']);
        $this->assertSame('unsupported_source_scope', $r['canonical_import_status']);
        $this->assertCount(2, $r['spend_inspection']);
        $this->assertSame('2026-11-10', $r['spend_inspection'][0]['date']);
        $this->assertEqualsWithDelta(12.5, $r['spend_inspection'][0]['spend'], 1e-9);
        $this->assertArrayNotHasKey('conversions', $r['spend_inspection'][0]);
        $this->assertArrayNotHasKey('impressions', $r['spend_inspection'][0]);
        Http::assertSent(function ($r) {
            return $r['granularity'] === 'DAY'
                && $r['start_time'] === '2026-11-10T00:00:00.000-08:00'
                && $r['end_time'] === '2026-11-12T00:00:00.000-08:00'
                && $r['fields'] === 'spend' && $r['omit_empty'] === 'false'
                && !isset($r['swipe_up_attribution_window']) && !isset($r['action_report_time']);
        });
    }

    public function test_long_range_is_split_into_chunks(): void
    {
        Http::fake(['adsapi.snapchat.com/*' => Http::response($this->statsJson([]))]);
        (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($this->account(), '2026-08-01', '2026-10-29'); // 90 days
        Http::assertSentCount(3);
    }

    public function test_missing_timezone_or_currency_pulls_nothing(): void
    {
        Http::fake();
        $ad = new SnapchatAdsAdapter($this->cfg());
        foreach ([['timezone' => null], ['currency' => null]] as $over) {
            try {
                $ad->fetchInsights($this->account($over, false), '2026-11-10', '2026-11-11');
                $this->fail('expected refusal');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('nothing was pulled', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_failed_sub_request_is_an_error_not_zeros(): void
    {
        $bad = $this->statsJson([]);
        $bad['timeseries_stats'][0]['sub_request_status'] = 'ERROR';
        Http::fake(['adsapi.snapchat.com/*' => Http::response($bad)]);
        $this->expectException(\RuntimeException::class);
        (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($this->account(), '2026-11-10', '2026-11-11');
    }

    public function test_401_triggers_one_refresh_then_retries(): void
    {
        Http::fake([
            'accounts.snapchat.com/*' => Http::response(['access_token' => 'new', 'refresh_token' => 'r2', 'expires_in' => 3600]),
            'adsapi.snapchat.com/*' => Http::sequence()->push([], 401)->push($this->statsJson([])),
        ]);
        $acc = $this->account();
        (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($acc, '2026-11-10', '2026-11-10');
        $this->assertSame('new', $acc->fresh()->access_token);
    }

    public function test_refresh_failure_marks_account_token_expired(): void
    {
        Http::fake(['accounts.snapchat.com/*' => Http::response([], 400), 'adsapi.snapchat.com/*' => Http::response([], 401)]);
        $acc = $this->account();
        try {
            (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($acc, '2026-11-10', '2026-11-10');
            $this->fail('expected exception');
        } catch (OAuthTokenExpiredException $e) {
            $this->assertSame('token_expired', $acc->fresh()->status);
        }
    }

    public function test_connect_refuses_account_without_currency_or_timezone(): void
    {
        $u = User::create(['name' => 'Snap2', 'email' => 'snap2_' . uniqid() . '@example.com', 'password' => bcrypt('x-test-only-1'), 'status' => 'active']);
        \Illuminate\Support\Facades\Cache::put('discovered_accounts:snapchat:' . $u->id, [
            'token_data' => ['access_token' => 't'],
            'accounts' => [['provider' => 'snapchat', 'external_account_id' => 'nocur1', 'account_name' => 'NoCur', 'currency' => null, 'timezone' => 'UTC']],
        ], 600);
        $this->withHeader('Authorization', 'Bearer ' . $u->createToken('t')->plainTextToken)->postJson('/api/v1/integrations/snapchat/connect', ['selected_account_ids' => ['nocur1']])
            ->assertStatus(422)->assertJsonPath('error.code', 'ACCOUNT_METADATA_MISSING');
        $this->assertDatabaseMissing('connected_ad_accounts', ['external_account_id' => 'nocur1']);
    }

    public function test_refresh_updates_token_expires_at_not_a_missing_column(): void
    {
        Http::fake([
            'accounts.snapchat.com/*' => Http::response(['access_token' => 'new', 'refresh_token' => 'r2', 'expires_in' => 1800]),
            'adsapi.snapchat.com/*' => Http::sequence()->push([], 401)->push($this->statsJson([])),
        ]);
        $acc = $this->account();
        (new SnapchatAdsAdapter($this->cfg()))->fetchInsights($acc, '2026-11-10', '2026-11-10');
        $fresh = $acc->fresh();
        $this->assertNotNull($fresh->token_expires_at);
        $this->assertEqualsWithDelta(now()->addSeconds(1800)->timestamp, $fresh->token_expires_at->timestamp, 5);
        $this->assertSame('r2', $fresh->refresh_token);
    }

    public function test_short_lifetime_is_not_extended(): void
    {
        Http::fake(['accounts.snapchat.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 30])]);
        $t = (new SnapchatAdsAdapter($this->cfg()))->handleOAuthCallback('c', 'https://x.example/cb');
        $this->assertEqualsWithDelta(now()->addSeconds(30)->timestamp, $t['expires_at']->timestamp, 5);
    }
}
