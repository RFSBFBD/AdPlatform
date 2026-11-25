<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\RedditAdsAdapter;
use App\Services\Advertising\Sync\RedditNativeRecordedMetrics;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic Reddit-shaped fixtures only (shape from the official v3 spec extract). No live API. */
final class RedditNativeCaptureTest extends TestCase
{
    private const BODY = '{"data":{"metrics":[{"date":"2026-11-01","currency":"EGP","spend":12000000,"impressions":9007199254740993,"clicks":3,"conversion_purchase_clicks":2,"conversion_purchase_views":1,"conversion_purchase_total_value":5000}]},"pagination":{}}';

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function prov(array $over = []): array
    {
        return array_replace_recursive(['request' => ['endpoint' => '/ad_accounts/t2_123/reports', 'api_version' => 'v3', 'body' => ['data' => ['starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-11-02T00:00:00Z',
            'fields' => RedditNativeRecordedMetrics::FIELDS, 'breakdowns' => ['DATE'], 'time_zone_id' => 'UTC']]], 'account_external_id' => 't2_123', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'currency' => 'EGP'], $over);
    }

    public function test_adapter_retains_original_bytes_and_extractor_keeps_exact_unscaled_tokens(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::preventStrayRequests();
        Http::fake(['*/reports*' => Http::response(str_replace('9007199254740993', '9007199254', self::BODY), 200, ['Content-Type' => 'application/json'])]);
        $adapter = new RedditAdsAdapter(new ProviderDeveloperConfig(['app_id' => 'x', 'app_secret' => 'y', 'is_enabled' => true]));
        $a = new ConnectedAdAccount(['external_account_id' => 't2_123', 'access_token' => 'fixture', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo']);
        $r = $adapter->fetchInsights($a, '2026-11-01', '2026-11-01');
        $page = $r['recorded_response_pages'][0];
        self::assertSame(str_replace('9007199254740993', '9007199254', self::BODY), $page['body']);
        self::assertSame(hash('sha256', $page['body']), $page['sha256']);
        $x = new RedditNativeRecordedMetrics;
        $n = $x->extract($page['body'], array_diff_key($page, ['body' => 1, 'sha256' => 1]));
        self::assertSame('native_recorded', $n['status']);
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('12000000', $by['spend']['native_value']);
        self::assertSame('none', $by['spend']['transformation']);
        self::assertSame('5000', $by['conversion_purchase_total_value']['native_value']);
        self::assertStringContainsString('unit_unstated_by_spec', $by['conversion_purchase_total_value']['unit']);
        self::assertSame('2', $by['conversion_purchase_clicks']['native_value']);
        self::assertSame('1', $by['conversion_purchase_views']['native_value']);
        self::assertSame('not_stated_by_spec', $n['requested_bounds']['ends_at_inclusivity']);
        $big = $x->extract(self::BODY, $this->prov());
        self::assertSame('9007199254740993', array_column($big['rows'], null, 'metric')['impressions']['native_value']);
    }

    public function test_null_is_unknown_and_changed_shapes_are_not_extracted(): void
    {
        $x = new RedditNativeRecordedMetrics;
        $n = $x->extract('{"data":{"metrics":[{"date":"2026-11-01","currency":"EGP","spend":1,"conversion_purchase_total_value":null}]}}', $this->prov());
        $by = array_column($n['rows'], null, 'metric');
        self::assertNull($by['conversion_purchase_total_value']['native_value']);
        self::assertSame('null_or_omitted_unknown_not_zero', $by['conversion_purchase_total_value']['availability']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['body' => ['data' => ['breakdowns' => ['DATE', 'AGE']]]]]))['status']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['body' => ['data' => ['time_zone_id' => 'Africa/Cairo']]]]))['status']);
        self::assertSame('unsupported_version', $x->extract(self::BODY, $this->prov(['request' => ['api_version' => 'v2']]))['status']);
    }

    public function test_malformed_pages_are_rejected_whole(): void
    {
        $x = new RedditNativeRecordedMetrics;
        $m = '"date":"2026-11-01","currency":"EGP","spend":1';
        foreach (['{"data":{"metrics":[{'.$m.'},{'.$m.'}]}}', '{"data":{"metrics":[{'.str_replace('"EGP"', '"USD"', $m).'}]}}', '{"data":{"metrics":[{'.str_replace('2026-11-01', '2026-11-02', $m).'}]}}',
            '{"data":{"metrics":[{'.$m.',"clicks":1.5}]}}', '{"data":[]}', '{"data":{"metrics":{"a":1}}}', '{"data":{"metrics":[{'.$m.',"clicks":true}]}}', '{"data":{"metrics":[{'.$m.',"clicks":-1}]}}'] as $body) {
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
