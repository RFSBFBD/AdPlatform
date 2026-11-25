<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Services\Advertising\Adapters\SpotifyAdsAdapter;
use App\Services\Advertising\Sync\SpotifyNativeRecordedMetrics;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic Spotify-shaped fixtures only (shape from the official v3 OpenAPI extract). No live API. */
final class SpotifyNativeCaptureTest extends TestCase
{
    private function body(array $stats, string $extra = ''): string
    {
        $s = [];
        foreach ($stats as $k => $v) {
            $s[] = '{"field_type":"'.$k.'","field_value":'.$v.'}';
        }

        return '{"continuation_token":null,"rows":[{"entity_type":"AD_ACCOUNT","entity_id":"acc1","start_time":"2026-11-01T00:00:00Z","end_time":"2026-11-02T00:00:00Z","stats":['.implode(',', $s).']'.$extra.'}]}';
    }

    private const OK = ['IMPRESSIONS' => '9007199254740993', 'CLICKS' => '3', 'SPEND' => '12.345678901234567', 'PURCHASES' => '7', 'REVENUE' => '99.50'];

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function prov(array $over = []): array
    {
        return array_replace_recursive(['request' => ['endpoint' => '/ad_accounts/acc1/aggregate_reports', 'api_version' => 'v3', 'entity_type' => 'AD_ACCOUNT', 'granularity' => 'DAY', 'fields' => SpotifyNativeRecordedMetrics::FIELDS,
            'report_start' => '2026-11-01T00:00:00Z', 'report_end' => '2026-11-01T00:00:00Z', 'limit' => 50], 'account_external_id' => 'acc1', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'currency' => 'USD'], $over);
    }

    public function test_adapter_retains_original_bytes_and_extractor_keeps_unscaled_tokens(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::preventStrayRequests();
        $b = $this->body(['IMPRESSIONS' => '1000', 'CLICKS' => '3', 'SPEND' => '12.5', 'PURCHASES' => '7', 'REVENUE' => '99.50']);
        Http::fake(['*' => Http::response($b, 200, ['Content-Type' => 'application/json'])]);
        $adapter = new SpotifyAdsAdapter(new ProviderDeveloperConfig(['app_id' => 'x', 'app_secret' => 'y', 'is_enabled' => true]));
        $r = $adapter->fetchInsights(new ConnectedAdAccount(['external_account_id' => 'acc1', 'access_token' => 'f', 'currency' => 'USD', 'timezone' => 'UTC']), '2026-11-01', '2026-11-01');
        $page = $r['recorded_response_pages'][0];
        self::assertSame($b, $page['body']);
        self::assertSame(hash('sha256', $b), $page['sha256']);
        $x = new SpotifyNativeRecordedMetrics;
        $n = $x->extract($page['body'], array_diff_key($page, ['body' => 1, 'sha256' => 1]));
        self::assertSame('native_recorded', $n['status']);
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('12.5', $by['SPEND']['native_value']);
        self::assertSame('none', $by['SPEND']['transformation']);
        self::assertStringContainsString('not_micro', $by['SPEND']['unit']);
        self::assertSame('99.50', $by['REVENUE']['native_value']);
        self::assertStringContainsString('not_verified_sales', $by['REVENUE']['unit']);
        $big = array_column($x->extract($this->body(self::OK), $this->prov())['rows'], null, 'metric');
        self::assertSame('9007199254740993', $big['IMPRESSIONS']['native_value']);
        self::assertSame('12.345678901234567', $big['SPEND']['native_value']);
    }

    public function test_privacy_sentinel_is_suppressed_not_zero_and_missing_is_unknown(): void
    {
        $x = new SpotifyNativeRecordedMetrics;
        $by = array_column($x->extract($this->body(['IMPRESSIONS' => '10', 'CLICKS' => '1', 'SPEND' => '1', 'PURCHASES' => '-5']), $this->prov())['rows'], null, 'metric');
        self::assertNull($by['PURCHASES']['native_value']);
        self::assertSame('-5', $by['PURCHASES']['native_sentinel']);
        self::assertSame('suppressed_privacy_sentinel_between_1_and_4_not_zero', $by['PURCHASES']['availability']);
        self::assertSame('omitted_unknown_not_zero', $by['REVENUE']['availability']);
        self::assertSame('unsupported_query_shape', $x->extract($this->body(self::OK), $this->prov(['request' => ['granularity' => 'HOUR']]))['status']);
        self::assertSame('unsupported_query_shape', $x->extract($this->body(self::OK), $this->prov(['request' => ['entity_type' => 'CAMPAIGN']]))['status']);
        self::assertSame('unsupported_version', $x->extract($this->body(self::OK), $this->prov(['request' => ['api_version' => 'v4']]))['status']);
    }

    public function test_malformed_pages_are_rejected_whole(): void
    {
        $x = new SpotifyNativeRecordedMetrics;
        $ok = self::OK;
        $bad = [
            $this->body(['IMPRESSIONS' => '-5'] + $ok),
            $this->body(['CLICKS' => '1.5'] + $ok),
            $this->body(['IMPRESSIONS' => 'null'] + $ok),
            $this->body(['CTR' => '0.1'] + $ok),
            str_replace('2026-11-01T00', '2026-11-01T05', $this->body($ok)),
            str_replace('"acc1"', '"other"', $this->body($ok)),
            '{"data":[]}',
            $this->body($ok, ',"x":1').substr($this->body($ok), 0),
        ];
        $dup = $this->body($ok);
        $bad[] = str_replace('"stats":[', '"stats":[{"field_type":"CLICKS","field_value":1},', $dup);
        foreach (array_slice($bad, 0, 7) + [8 => $bad[8]] as $body) {
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
