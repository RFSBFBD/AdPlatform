<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\ExchangeRate;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\Currency\CurrencyConversionService;
use App\Services\Currency\DatabaseExchangeRateProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CurrencyConversionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $eurAccount;
    protected ConnectedAdAccount $usdAccount;
    protected Campaign $eurCampaign;
    protected Campaign $usdCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'currency_tester@example.com'],
            ['name' => 'Currency Tester', 'password' => bcrypt('password123'), 'status' => 'active', 'reporting_currency' => 'USD']
        );

        $this->eurAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_eur_123',
            'account_name' => 'European Store EUR',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'access_token' => 'dummy_token',
            'status' => 'active',
        ]);

        $this->usdAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'external_account_id' => 'act_usd_456',
            'account_name' => 'US Store USD',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'access_token' => 'dummy_token',
            'status' => 'active',
        ]);

        $this->eurCampaign = Campaign::create([
            'connected_ad_account_id' => $this->eurAccount->id,
            'provider' => 'meta',
            'external_campaign_id' => 'meta_eur_camp',
            'name' => 'Euro Campaign',
            'status' => 'ACTIVE',
            'currency' => 'EUR',
        ]);

        $this->usdCampaign = Campaign::create([
            'connected_ad_account_id' => $this->usdAccount->id,
            'provider' => 'google',
            'external_campaign_id' => 'google_usd_camp',
            'name' => 'USD Campaign',
            'status' => 'ACTIVE',
            'currency' => 'USD',
        ]);
    }

    public function test_service_converts_direct_and_inverse_rates(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        $provider->storeRate('EUR', 'USD', 1.0850, '2026-11-15', 'ECB');

        $service = new CurrencyConversionService($provider);

        // Direct conversion: 100 EUR to USD
        $resDirect = $service->convert(100.00, 'EUR', 'USD', '2026-11-15');
        $this->assertEquals('success', $resDirect['status']);
        $this->assertEquals(108.50, $resDirect['converted_amount']);
        $this->assertTrue($resDirect['is_converted']);

        // Inverse conversion: 108.50 USD to EUR
        $resInverse = $service->convert(108.50, 'USD', 'EUR', '2026-11-15');
        $this->assertEquals('success', $resInverse['status']);
        $this->assertEquals(100.00, round($resInverse['converted_amount'], 2));

        // Same currency conversion
        $resSame = $service->convert(50.00, 'EUR', 'EUR', '2026-11-15');
        $this->assertEquals(50.00, $resSame['converted_amount']);
        $this->assertFalse($resSame['is_converted']);
    }

    public function test_service_explicitly_reports_missing_exchange_rates(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        $service = new CurrencyConversionService($provider);

        // Missing rate without throwing
        $result = $service->convert(100.00, 'GBP', 'JPY', '2026-11-15');
        $this->assertEquals('missing_rate', $result['status']);
        $this->assertNull($result['converted_amount']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_overview_endpoint_converts_mixed_currencies_using_historical_rates(): void
    {
        // Store historical rate: 1 EUR = 1.10 USD on 2026-11-15
        ExchangeRate::create([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'exchange_rate' => 1.1000,
            'effective_date' => '2026-11-15',
            'source' => 'ECB',
        ]);

        // EUR metric: 100 EUR spend, 330 EUR conv value on 2026-11-15
        PerformanceMetric::create([
            'connected_ad_account_id' => $this->eurAccount->id,
            'campaign_id' => $this->eurCampaign->id,
            'provider' => 'meta',
            'date' => '2026-11-15',
            'spend' => 100.00,
            'impressions' => 1000,
            'clicks' => 100,
            'conversions' => 10,
            'conversion_value' => 330.00,
            'currency' => 'EUR',
        ]);

        // USD metric: 200 USD spend, 400 USD conv value on 2026-11-15
        PerformanceMetric::create([
            'connected_ad_account_id' => $this->usdAccount->id,
            'campaign_id' => $this->usdCampaign->id,
            'provider' => 'google',
            'date' => '2026-11-15',
            'spend' => 200.00,
            'impressions' => 2000,
            'clicks' => 200,
            'conversions' => 20,
            'conversion_value' => 400.00,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/overview?start_date=2026-11-01&end_date=2026-11-20&reporting_currency=USD');

        $response->assertStatus(200);
        $data = $response->json();

        // 100 EUR * 1.10 = 110 USD. Total spend = 110 + 200 = 310 USD.
        $this->assertEquals(310.00, $data['summary']['spend']);
        // Conversion value: 330 EUR * 1.10 = 363 USD. Total = 363 + 400 = 763 USD.
        $this->assertEquals(763.00, $data['summary']['conversion_value']);
        $this->assertEquals('USD', $data['reporting_currency']);

        // Check currency breakdown maintains native figures
        $this->assertTrue($data['conversion_metadata']['has_mixed_currencies']);
        $this->assertTrue($data['conversion_metadata']['conversion_complete']);

        $currencies = collect($data['currency_breakdown'])->keyBy('currency');
        $this->assertEquals(100.00, $currencies['EUR']['spend']);
        $this->assertEquals(200.00, $currencies['USD']['spend']);
    }

    public function test_overview_endpoint_handles_missing_rates_transparently(): void
    {
        // Create metric in un-rated currency (SAR)
        $sarAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'tiktok',
            'external_account_id' => 'act_sar_789',
            'account_name' => 'Saudi Store SAR',
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'access_token' => 'dummy_token',
            'status' => 'active',
        ]);

        PerformanceMetric::create([
            'connected_ad_account_id' => $sarAccount->id,
            'provider' => 'tiktok',
            'date' => '2026-11-15',
            'spend' => 500.00,
            'impressions' => 5000,
            'clicks' => 250,
            'conversions' => 15,
            'conversion_value' => 1500.00,
            'currency' => 'SAR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/overview?start_date=2026-11-01&end_date=2026-11-20&reporting_currency=USD');

        $response->assertStatus(200);
        $data = $response->json();

        // Should flag incomplete conversion and list SAR
        $this->assertFalse($data['conversion_metadata']['conversion_complete']);
        $this->assertContains('SAR', $data['conversion_metadata']['missing_rate_currencies']);
        $this->assertTrue($data['conversion_metadata']['is_partial']);
        $this->assertNotEmpty($data['conversion_metadata']['unconverted_totals']);
    }

    public function test_service_validates_iso_4217_currency_codes(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        $service = new CurrencyConversionService($provider);

        $this->expectException(\InvalidArgumentException::class);
        $service->convert(100.0, 'INVALID', 'USD', '2026-11-15');
    }

    public function test_provider_rejects_non_positive_exchange_rate(): void
    {
        $provider = new DatabaseExchangeRateProvider();

        $this->expectException(\InvalidArgumentException::class);
        $provider->storeRate('EUR', 'USD', 0.0, '2026-11-15');
    }

    public function test_service_exposes_actual_effective_date_and_fallback_status(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        // Store rate on 2026-11-10
        $provider->storeRate('EUR', 'USD', 1.08, '2026-11-10', 'ECB');

        $service = new CurrencyConversionService($provider);
        // Request conversion for 2026-11-12 (which should fall back to 2026-11-10 within 7 days)
        $result = $service->convert(100.0, 'EUR', 'USD', '2026-11-12');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(108.0, $result['converted_amount']);
        $this->assertEquals('2026-11-12', $result['requested_date']);
        $this->assertEquals('2026-11-10', $result['effective_date']);
        $this->assertTrue($result['is_fallback']);
    }

    public function test_unconverted_rows_do_not_distort_cpc_cpm_cpa_roas(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        // Rate for EUR -> USD is 1.0
        $provider->storeRate('EUR', 'USD', 1.0, '2026-11-15', 'ECB');
        $service = new CurrencyConversionService($provider);

        // Row 1: EUR (convertible) - 100 EUR spend, 1000 impr, 100 clicks, 10 conv, 500 EUR value
        // Row 2: JPY (unconvertible, missing rate) - 10000 JPY spend, 9000 impr, 900 clicks, 90 conv, 50000 JPY value
        $rows = [
            (object) [
                'currency' => 'EUR',
                'date' => '2026-11-15',
                'spend' => 100.0,
                'impressions' => 1000,
                'clicks' => 100,
                'conversions' => 10.0,
                'conversion_value' => 500.0,
            ],
            (object) [
                'currency' => 'JPY',
                'date' => '2026-11-15',
                'spend' => 10000.0,
                'impressions' => 9000,
                'clicks' => 900,
                'conversions' => 90.0,
                'conversion_value' => 50000.0,
            ],
        ];

        $res = $service->convertMetricsRows($rows, 'USD');

        // Converted spend is strictly 100.00 USD
        $this->assertEquals(100.0, $res['summary']['converted_spend']);
        // Crucial: CPC must be 100 spend / 100 converted clicks = $1.00 (NOT 100 / 1000 = $0.10)
        $this->assertEquals(1.00, $res['summary']['cpc']);
        // CPM must be (100 / 1000 converted impr) * 1000 = $100.00 (NOT 100 / 10000 = $10.00)
        $this->assertEquals(100.00, $res['summary']['cpm']);
        // CPA must be 100 spend / 10 converted conv = $10.00 (NOT 100 / 100 = $1.00)
        $this->assertEquals(10.00, $res['summary']['cpa']);
        // ROAS must be 500 value / 100 spend = 5.00
        $this->assertEquals(5.00, $res['summary']['roas']);

        $this->assertTrue($res['summary']['is_partial']);
        $this->assertFalse($res['conversion_metadata']['conversion_complete']);
    }

    public function test_campaigns_endpoint_aggregates_metrics_in_reporting_currency(): void
    {
        $provider = new DatabaseExchangeRateProvider();
        $provider->storeRate('EUR', 'USD', 1.10, '2026-11-15', 'ECB');

        PerformanceMetric::create([
            'connected_ad_account_id' => $this->eurAccount->id,
            'campaign_id' => $this->eurCampaign->id,
            'provider' => 'meta',
            'date' => '2026-11-15',
            'spend' => 100.00,
            'impressions' => 1000,
            'clicks' => 50,
            'conversions' => 5,
            'conversion_value' => 330.00,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/campaigns?reporting_currency=USD');

        $response->assertStatus(200);
        $data = $response->json('data');

        $eurCamp = collect($data)->firstWhere('id', $this->eurCampaign->id);
        $this->assertNotNull($eurCamp);
        // Converted spend in USD should be 100 * 1.10 = 110.00
        $this->assertEquals(110.00, $eurCamp['metrics']['spend']);
        $this->assertEquals(100.00, $eurCamp['metrics']['native_spend']);
        $this->assertEquals('USD', $eurCamp['metrics']['currency']);
    }
}
