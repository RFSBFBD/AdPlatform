<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\AppleAdsAdapter;
use App\Services\Advertising\Sync\AppleNativeRecordedMetrics;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic Apple-shaped fixtures only (shape from the Apple Ads Platform API docs). No live API. */
final class AppleNativeCaptureTest extends TestCase
{
    private function body(string $m = '"impressions":9007199254740993,"taps":3,"totalInstalls":2,"localSpend":{"amount":"12.345678","currency":"USD"}', string $meta = '"id":1,"adAccountId":123'): string
    {
        return '{"result":{"rows":[{"metadata":{'.$meta.'},"totalMetrics":{'.$m.'}}]},"pagination":{"offset":0,"pageSize":100,"totalCount":1},"error":null}';
    }

    private function prov(array $over = []): array
    {
        return array_replace_recursive(['request' => ['endpoint' => '/reports/apps/campaigns/query', 'api_version' => 'v1', 'timeRange' => ['start' => '2026-11-01', 'end' => '2026-11-01', 'timeZone' => 'UTC']],
            'account_external_id' => '123', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'currency' => 'USD'], $over);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_adapter_retains_bytes_and_extractor_keeps_tokens_without_conversion(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::preventStrayRequests();
        $b = $this->body('"impressions":1000,"taps":3,"totalInstalls":2,"localSpend":{"amount":"12.345678","currency":"USD"}');
        Http::fake(['*' => Http::response($b, 200, ['Content-Type' => 'application/json'])]);
        $a = new AppleAdsAdapter(new ProviderDeveloperConfig(['app_id' => 'x', 'app_secret' => 'y', 'is_enabled' => true]));
        $r = $a->deliveryReport(new ConnectedAdAccount(['external_account_id' => '123', 'access_token' => 'f', 'currency' => 'USD', 'timezone' => 'UTC']), '2026-11-01', '2026-11-01');
        $page = $r['recorded_response_pages'][0];
        self::assertSame($b, $page['body']);
        self::assertSame(hash('sha256', $b), $page['sha256']);
        $n = (new AppleNativeRecordedMetrics)->extract($page['body'], array_diff_key($page, ['body' => 1, 'sha256' => 1]));
        self::assertSame('native_recorded', $n['status']);
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('1000', $by['impressions']['native_value']);
        $big = array_column((new AppleNativeRecordedMetrics)->extract($this->body(), $this->prov())['rows'], null, 'metric');
        self::assertSame('9007199254740993', $big['impressions']['native_value']);
        self::assertSame('12.345678', $by['localSpend']['native_value']);
        self::assertSame('none', $by['localSpend']['transformation']);
        self::assertStringContainsString('not_purchases', $by['totalInstalls']['unit']);
        self::assertSame('2026-11-01', $by['taps']['reporting_date']);
    }

    public function test_daily_granular_empty_and_unsupported_shapes(): void
    {
        $x = new AppleNativeRecordedMetrics;
        $g = '{"result":{"rows":[{"metadata":{"id":1,"adAccountId":123},"granularMetrics":[{"date":"2026-11-01","impressions":1,"taps":1,"totalInstalls":0,"localSpend":{"amount":"1.00","currency":"USD"}},{"date":"2026-11-02","impressions":2,"taps":1,"totalInstalls":1}]}]},"pagination":{"totalCount":1}}';
        $p = $this->prov(['request' => ['timeRange' => ['end' => '2026-11-02', 'granularity' => 'DAILY']], 'end_date' => '2026-11-02']);
        $by = $x->extract($g, $p)['rows'];
        self::assertCount(8, $by);
        $sp = array_values(array_filter($by, fn ($r) => $r['metric'] === 'localSpend' && $r['reporting_date'] === '2026-11-02'))[0];
        self::assertSame('omitted_unknown_not_zero', $sp['availability']);
        self::assertNull($sp['native_value']);
        self::assertSame('native_recorded', $x->extract('{"result":{},"pagination":{"totalCount":0}}', $this->prov())['status']);
        self::assertSame([], $x->extract('{"result":{},"pagination":{"totalCount":0}}', $this->prov())['rows']);
        self::assertSame('unsupported_query_shape', $x->extract($this->body(), $this->prov(['request' => ['timeRange' => ['timeZone' => 'ORTZ']]]))['status']);
        self::assertSame('unsupported_query_shape', $x->extract($this->body(), $this->prov(['request' => ['timeRange' => ['end' => '2026-11-02']]]))['status']);
        self::assertSame('unsupported_version', $x->extract($this->body(), $this->prov(['request' => ['api_version' => 'v5']]))['status']);
    }

    public function test_malformed_pages_are_rejected_whole(): void
    {
        $x = new AppleNativeRecordedMetrics;
        $bad = [
            $this->body(meta: '"id":1,"adAccountId":999'),
            $this->body(meta: '"id":1'),
            $this->body('"impressions":1.5,"taps":1,"totalInstalls":1'),
            $this->body('"impressions":1,"taps":1,"totalInstalls":1,"localSpend":{"amount":"1.0","currency":"EUR"}'),
            '{"data":[]}',
            '{"result":{"rows":[]},"error":{"message":"x"}}',
        ];
        foreach ($bad as $body) {
            $threw = false;
            try {
                $x->extract($body, $this->prov());
            } catch (\InvalidArgumentException|\JsonException) {
                $threw = true;
            }
            self::assertTrue($threw, $body);
        }
    }
}
