<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\User;
use App\Services\Advertising\Adapters\PinterestAdsAdapter;
use App\Services\Advertising\Sync\PinterestNativeRecordedMetrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic Pinterest-shaped fixtures only (shape from the official OpenAPI 5.32.0). No live API. */
final class PinterestNativeCaptureTest extends TestCase
{
    use DatabaseTransactions;

    private const BODY = '[{"AD_ACCOUNT_ID":"123","DATE":"2026-11-01","SPEND_IN_MICRO_DOLLAR":12345678,"PAID_IMPRESSION":9007199254,"OUTBOUND_CLICK_1":3,"TOTAL_CHECKOUT":2.50,"TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR":40123456}]';

    private function account(): ConnectedAdAccount
    {
        return ConnectedAdAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'pinterest', 'external_account_id' => '123', 'account_name' => 'Synthetic', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture']);
    }

    private function prov(array $over = []): array
    {
        return array_replace_recursive(['request' => ['endpoint' => '/ad_accounts/123/analytics', 'api_version' => 'v5', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'columns' => PinterestNativeRecordedMetrics::COLUMNS, 'granularity' => 'DAY',
            'click_window_days' => 30, 'engagement_window_days' => 30, 'view_window_days' => 1, 'conversion_report_time' => 'TIME_OF_AD_ACTION', 'reporting_timezone' => 'AD_ACCOUNT_TIME_ZONE'],
            'account_external_id' => '123', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'currency' => 'USD'], $over);
    }

    public function test_adapter_retains_original_bytes_and_extractor_keeps_exact_tokens(): void
    {
        \Carbon\Carbon::setTestNow('2026-11-23');
        Http::preventStrayRequests();
        Http::fake(['*/analytics*' => Http::response(self::BODY, 200, ['Content-Type' => 'application/json'])]);
        $a = $this->account();
        $r = (new PinterestAdsAdapter)->fetchInsights($a, '2026-11-01', '2026-11-01');
        $page = $r['recorded_response_pages'][0];
        self::assertSame(self::BODY, $page['body']);
        self::assertSame(hash('sha256', self::BODY), $page['sha256']);
        $n = (new PinterestNativeRecordedMetrics)->extract($page['body'], array_diff_key($page, ['body' => 1, 'sha256' => 1]));
        self::assertSame('native_recorded', $n['status']);
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('12345678', $by['SPEND_IN_MICRO_DOLLAR']['native_value']);
        $big = (new PinterestNativeRecordedMetrics)->extract('[{"AD_ACCOUNT_ID":"123","DATE":"2026-11-01","SPEND_IN_MICRO_DOLLAR":12345678901234567,"PAID_IMPRESSION":9007199254740993}]', $this->prov());
        self::assertSame('12345678901234567', array_column($big['rows'], null, 'metric')['SPEND_IN_MICRO_DOLLAR']['native_value']);
        self::assertSame('9007199254740993', array_column($big['rows'], null, 'metric')['PAID_IMPRESSION']['native_value']);
        self::assertSame('none', $by['SPEND_IN_MICRO_DOLLAR']['transformation']);
        self::assertSame('USD', $by['SPEND_IN_MICRO_DOLLAR']['currency_candidate']);
        self::assertSame('9007199254', $by['PAID_IMPRESSION']['native_value']);
        self::assertSame('2.50', $by['TOTAL_CHECKOUT']['native_value']);
        self::assertStringContainsString('unmatched_outcome', $by['TOTAL_CHECKOUT']['unit']);
        self::assertStringContainsString('not_sales', $by['TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR']['unit']);
        self::assertSame(30, $n['attribution']['click_window_days']);
    }

    public function test_omitted_is_unknown_and_changed_query_shapes_are_not_extracted(): void
    {
        $x = new PinterestNativeRecordedMetrics;
        $n = $x->extract('[{"AD_ACCOUNT_ID":"123","DATE":"2026-11-01","SPEND_IN_MICRO_DOLLAR":1}]', $this->prov());
        $by = array_column($n['rows'], null, 'metric');
        self::assertSame('omitted_unknown_not_zero', $by['TOTAL_CHECKOUT']['availability']);
        self::assertNull($by['TOTAL_CHECKOUT']['native_value']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['granularity' => 'TOTAL']]))['status']);
        self::assertSame('unsupported_query_shape', $x->extract(self::BODY, $this->prov(['request' => ['reporting_timezone' => 'PINTEREST_TIME_ZONE']]))['status']);
        self::assertSame('unsupported_version', $x->extract(self::BODY, $this->prov(['request' => ['api_version' => 'v6']]))['status']);
    }

    public function test_malformed_pages_are_rejected_whole(): void
    {
        $x = new PinterestNativeRecordedMetrics;
        $ok = '"AD_ACCOUNT_ID":"123","DATE":"2026-11-01","SPEND_IN_MICRO_DOLLAR":1,"PAID_IMPRESSION":2,"OUTBOUND_CLICK_1":3';
        foreach (['[{'.$ok.'},{'.$ok.'}]', '[{'.str_replace('"123"', '"999"', $ok).'}]', '[{'.str_replace('2026-11-01', '2026-12-01', $ok).'}]', '[{'.str_replace('"PAID_IMPRESSION":2', '"PAID_IMPRESSION":2.5', $ok).'}]',
            '{"items":[]}', '[{'.str_replace('"SPEND_IN_MICRO_DOLLAR":1', '"SPEND_IN_MICRO_DOLLAR":"x"', $ok).'}]', '[{'.str_replace('"SPEND_IN_MICRO_DOLLAR":1', '"SPEND_IN_MICRO_DOLLAR":-1', $ok).'}]'] as $body) {
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
