<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\User;
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use App\Services\Advertising\Sync\MetaNativeRecordedMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Synthetic Meta-shaped fixtures only (shapes from the documented Insights/AdsActionStats references). No live API. */
final class MetaNativeCaptureTest extends TestCase
{
    use DatabaseTransactions;

    private const BODY = '{"data":[{"campaign_id":"120001","campaign_name":"A","date_start":"2026-11-01","date_stop":"2026-11-01","spend":"12345.123456789","impressions":"9007199254","clicks":"3","actions":[{"action_type":"offsite_conversion.fb_pixel_purchase","value":"2"},{"action_type":"omni_purchase","value":"2"},{"action_type":"lead","value":"10"}],"action_values":[{"action_type":"offsite_conversion.fb_pixel_purchase","value":"40.123456789012345"}]}]}';

    private function account(): ConnectedAdAccount
    {
        return ConnectedAdAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'meta', 'external_account_id' => '555', 'account_name' => 'Synthetic', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture',
            'metadata' => ['metadata_source' => ['version' => 'meta-discovery-explicit-1', 'currency' => 'USD', 'timezone' => 'UTC']]]);
    }

    public function test_sync_retains_original_bytes_and_preserves_native_tokens_without_outcome_claims(): void
    {
        $account = $this->account();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/insights')
            ? Http::response(self::BODY, 200, ['Content-Type' => 'application/json'])
            : Http::response(['data' => [['id' => '120001', 'name' => 'A']]]));
        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('getAdapter')->andReturn(new MetaAdsAdapter);
        $run = (new AdDataSyncEngine($manager))->syncAccount($account, 'manual', '2026-11-01', '2026-11-01');
        self::assertSame('completed', $run->status, $run->error_message ?? '');
        $row = DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->first();
        self::assertNotNull($row);
        self::assertSame(self::BODY, $row->body);
        self::assertSame(hash('sha256', self::BODY), $row->body_sha256);
        self::assertSame('unqualified_original_reporting_bytes', $row->admission_state);
        $native = json_decode($row->native_metrics_json, true);
        self::assertSame('native_recorded', $native['status']);
        self::assertSame('documented_contract_not_live_verified', $native['api_lifecycle']);
        $by = array_column($native['rows'], null, 'metric');
        self::assertSame('12345.123456789', $by['spend']['native_value']);
        self::assertSame('USD', $by['spend']['currency_candidate']);
        self::assertSame('9007199254', $by['impressions']['native_value']);
        $big = (new MetaNativeRecordedMetrics)->extract('{"data":[{"campaign_id":"1","date_start":"2026-11-01","date_stop":"2026-11-01","spend":"1","impressions":"9007199254740993"}]}', $this->prov());
        self::assertSame('9007199254740993', array_column($big['rows'], null, 'metric')['impressions']['native_value']);
        self::assertSame('2', $by['actions:offsite_conversion.fb_pixel_purchase']['native_value']);
        self::assertSame('2', $by['actions:omni_purchase']['native_value']);
        self::assertSame('10', $by['actions:lead']['native_value']);
        self::assertSame('40.123456789012345', $by['action_values:offsite_conversion.fb_pixel_purchase']['native_value']);
        self::assertSame('provider_default_window_action_count_unmatched_outcome', $by['actions:lead']['unit']);
        foreach ($native['rows'] as $r) {
            self::assertSame('native_recorded_not_canonical_qualified', $r['admission_state']);
            self::assertStringStartsWith('/data/0/', $r['source_pointer']);
        }
    }

    private function prov(array $over = []): array
    {
        return array_replace_recursive(['request' => ['endpoint' => '/act_555/insights', 'act_id' => 'act_555', 'api_version' => 'v21.0', 'level' => 'campaign', 'fields' => MetaNativeRecordedMetrics::FIELDS, 'time_increment' => 1, 'time_range' => ['since' => '2026-11-01', 'until' => '2026-11-01']],
            'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'currency' => 'USD'], $over);
    }

    public function test_missing_is_unknown_not_zero_and_unsupported_shapes_are_not_extracted(): void
    {
        $x = new MetaNativeRecordedMetrics;
        $n = $x->extract('{"data":[{"campaign_id":"1","date_start":"2026-11-01","date_stop":"2026-11-01","spend":"1","impressions":"2"}]}', $this->prov());
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('omitted_unknown_not_zero', $by['clicks']['availability']);
        self::assertNull($by['clicks']['native_value']);
        self::assertSame('omitted_unknown_not_zero', $by['actions']['availability']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['breakdowns' => 'age']]))['status']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['action_attribution_windows' => ['7d_click']]]))['status']);
        self::assertSame('unsupported_version', $x->extract(self::BODY, $this->prov(['request' => ['api_version' => 'latest']]))['status']);
    }

    public function test_malformed_native_pages_are_rejected_whole(): void
    {
        $x = new MetaNativeRecordedMetrics;
        $row = '"campaign_id":"1","date_start":"2026-11-01","date_stop":"2026-11-01","spend":"1","impressions":"2","clicks":"3"';
        foreach ([
            '{"data":[{'.$row.'},{'.$row.'}]}',
            '{"data":[{'.str_replace('"2026-11-01","date_stop":"2026-11-01"', '"2026-11-01","date_stop":"2026-11-02"', $row).'}]}',
            '{"data":[{'.str_replace('"2026-11-01"', '"2026-12-01"', $row).'}]}',
            '{"data":[{'.$row.',"actions":[{"action_type":"lead","value":"1"},{"action_type":"lead","value":"2"}]}]}',
            '{"data":[{'.$row.',"actions":"x"}]}',
            '{"error":{"message":"x"}}',
        ] as $body) {
            $threw = false;
            try {
                $x->extract($body, $this->prov());
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            self::assertTrue($threw, $body);
        }
    }
}
