<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use App\Services\Advertising\Adapters\XAdsAdapter;
use App\Services\Advertising\Reports\XDeliveryParser;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function adapter(): XAdsAdapter
    {
        return new XAdsAdapter(new ProviderDeveloperConfig(['app_id' => 'fixture-key', 'app_secret' => 'fixture-secret', 'is_enabled' => true]));
    }

    private function stats(): array
    {
        return ['data_type' => 'stats', 'time_series_length' => 2, 'data' => [['id' => 'c1', 'id_data' => [['segment' => null, 'metrics' => ['impressions' => [10, 20], 'clicks' => [1, 2], 'billed_charge_local_micro' => [5500000, 1000000]]]]]]];
    }

    public function test_oauth1_form_tokens_are_bound_and_consumed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.x.com/oauth/request_token' => Http::response('oauth_token=req&oauth_token_secret=secret&oauth_callback_confirmed=true', 200, ['Content-Type' => 'application/x-www-form-urlencoded']), 'api.x.com/oauth/access_token' => Http::response('oauth_token=access&oauth_token_secret=pair', 200, ['Content-Type' => 'application/x-www-form-urlencoded'])]);
        $a = $this->adapter();
        $this->assertStringContainsString('https://api.x.com/oauth/authorize?oauth_token=req', $a->getAuthorizationUrl('state', 'https://example.test/cb?state=state'));
        $this->assertStringNotContainsString('secret', (string) Cache::get('x-oauth:state'));
        try {
            $a->complete('state', 'wrong', 'v');
            $this->fail('Mismatch accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mismatch', $e->getMessage());
        }
        $t = $a->complete('state', 'req', 'v');
        $this->assertSame(['token' => 'access', 'token_secret' => 'pair'], json_decode($t['access_token'], true));
        $this->assertNull($t['refresh_token']);
        $this->assertNull(Cache::get('x-oauth:state'));
        Http::assertSentCount(2);Http::assertSent(fn($r)=>str_contains($r->url(),'request_token') && $r->body()==='');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'request_token') && str_starts_with($r->header('Authorization')[0] ?? '', 'OAuth ') && str_contains($r->header('Authorization')[0] ?? '', 'oauth_callback='));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'access_token') && str_contains($r->header('Authorization')[0] ?? '', 'oauth_verifier='));
    }

    public function test_json_token_compatibility_and_callback_confirmation_gate(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->push(['oauth_token' => 'r', 'oauth_token_secret' => 's', 'oauth_callback_confirmed' => true])->push(['oauth_token' => 'r', 'oauth_token_secret' => 's'])]);
        $this->assertStringContainsString('oauth_token=r', $this->adapter()->getAuthorizationUrl('s', 'https://example.test/cb'));
        $this->expectException(\RuntimeException::class);
        $this->adapter()->getAuthorizationUrl('s2', 'https://example.test/cb');
    }

    public function test_parser_aligns_arrays_not_segments_and_scales_micros(): void
    {
        $r = (new XDeliveryParser)->parse($this->stats(), ['c1'], '2026-10-31', '2026-11-01', 'America/New_York', 'EUR');
        $this->assertSame('2026-11-01', $r[1]['date']);
        $this->assertSame(5.5, $r[0]['spend']);
        $this->assertSame('5.500000', $r[0]['spend_exact']);
        $this->assertSame(20, $r[1]['impressions']);
        $this->assertNull($r[0]['revenue']);
        $this->assertSame('EUR', $r[0]['currency']);
    }

    public function test_parser_rejects_null_segmented_incomplete_or_misaligned_reports(): void
    {
        foreach (['null', 'segment', 'missing', 'length', 'duplicate'] as $case) {
            $j = $this->stats();
            if ($case === 'null') {
                $j['data'][0]['id_data'][0]['metrics']['clicks'][0] = null;
            }if ($case === 'segment') {
                $j['data'][0]['id_data'][0]['segment'] = ['platform' => 'x'];
            }if ($case === 'missing') {
                $j['data'] = [];
            }if ($case === 'length') {
                $j['time_series_length'] = 1;
            }if ($case === 'duplicate') {
                $j['data'][] = $j['data'][0];
            }try {
                (new XDeliveryParser)->parse($j, ['c1'], '2026-10-31', '2026-11-01', 'America/New_York', 'USD');
                $this->fail('Invalid report accepted '.$case);
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_delivery_uses_local_dst_boundaries_and_no_conversion_fabrication(): void
    {
        CarbonImmutable::setTestNow('2026-11-23');
        Http::preventStrayRequests();
        Http::fake(['*/funding_instruments*' => Http::response(['data' => [['id' => 'f1', 'currency' => 'USD']]]), '*/campaigns*' => Http::response(['data' => [['id' => 'c1', 'funding_instrument_id' => 'f1']]]), '*/stats/*' => Http::response($this->stats())]);
        try {
            $r = $this->adapter()->deliveryReport(new ConnectedAdAccount(['external_account_id' => 'a', 'access_token' => json_encode(['token' => 't', 'token_secret' => 's']), 'currency' => 'USD', 'timezone' => 'America/New_York']), '2026-10-31', '2026-11-01');
            $this->assertFalse($r['revenue_optimizer_eligible']);
            Http::assertSent(fn ($r) => str_contains($r->url(), '/stats/') && $r['start_time'] === '2026-10-31T00:00:00-04:00' && $r['end_time'] === '2026-11-02T00:00:00-05:00' && $r['granularity'] === 'DAY');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_oauth_middleware_really_signs_token_and_verifier_without_bearer(): void
    {
        $captured = [];
        $mock = new MockHandler([new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push(new Oauth1(['consumer_key' => 'k', 'consumer_secret' => 's', 'token' => 't', 'token_secret' => 'ts', 'verifier' => 'v']));
        $stack->push(Middleware::history($captured));
        (new Client(['handler' => $stack]))->post('https://api.x.com/oauth/access_token', ['auth' => 'oauth']);
        $h = $captured[0]['request']->getHeaderLine('Authorization');
        $this->assertStringStartsWith('OAuth ', $h);
        $this->assertStringContainsString('oauth_verifier="v"', $h);
        $this->assertStringContainsString('oauth_token="t"', $h);
        $this->assertStringContainsString('oauth_signature=', $h);
        $this->assertStringNotContainsString('Bearer', $h);
    }

    public function test_http_callback_enforces_owner_state_then_discovers_and_connects_encrypted_pair(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        config(['oauth.allowed_redirect_uris' => ['https://example.test/accounts']]);
        ProviderDeveloperConfig::create(['provider' => 'x', 'app_id' => 'k', 'app_secret' => 's', 'is_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.x.com/oauth/request_token' => Http::response('oauth_token=req&oauth_token_secret=secret&oauth_callback_confirmed=true'), 'api.x.com/oauth/access_token' => Http::response('oauth_token=access&oauth_token_secret=pair'), '*/funding_instruments*' => Http::response(['data' => [['id' => 'f', 'currency' => 'USD']]]), '*/12/accounts*' => Http::response(['data' => [['id' => 'a', 'name' => 'Fixture X', 'timezone' => 'America/New_York']]])]);
        $r = $this->getJson('/api/v1/integrations/oauth/x/redirect?redirect_uri='.urlencode('https://example.test/accounts'))->assertOk();
        $state = $r->json('data.state');
        $q = http_build_query(['state' => $state, 'oauth_token' => 'req', 'oauth_verifier' => 'v', 'redirect_uri' => 'https://example.test/accounts']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/integrations/oauth/x/callback?'.$q)->assertStatus(400);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/integrations/oauth/x/callback?'.$q)->assertOk()->assertJsonPath('data.accounts.0.currency', 'USD');
        $this->postJson('/api/v1/integrations/x/connect', ['selected_account_ids' => ['a']])->assertOk();
        $acc = ConnectedAdAccount::where('provider', 'x')->firstOrFail();
        $this->assertSame('pair', json_decode($acc->access_token, true)['token_secret']);
        $this->assertStringNotContainsString('pair', $acc->getRawOriginal('access_token'));
        $this->assertNull($acc->refresh_token);
        $this->getJson('/api/v1/integrations/oauth/x/callback?'.$q)->assertStatus(400);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/ad-accounts/'.$acc->id.'/x-delivery-report?start_date=2026-11-01&end_date=2026-11-02')->assertNotFound();
    }
}
