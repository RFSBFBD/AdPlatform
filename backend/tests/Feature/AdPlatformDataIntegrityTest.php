<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\Adapters\MetaAdsAdapter;
use App\Services\Advertising\Adapters\TikTokAdsAdapter;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdPlatformDataIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $googleAccount;
    protected ConnectedAdAccount $tiktokAccount;
    protected ConnectedAdAccount $metaAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'data_integrity@example.com'],
            ['name' => 'Data Integrity Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'google'],
            [
                'app_id' => 'google-test-client-id',
                'app_secret' => 'google-test-secret',
                'developer_token' => 'dev_token_999',
                'is_enabled' => true,
            ]
        );

        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'tiktok'],
            [
                'app_id' => 'tiktok-test-app-id',
                'app_secret' => 'tiktok-test-secret',
                'is_enabled' => true,
            ]
        );

        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'meta'],
            [
                'app_id' => 'meta-test-app-id',
                'app_secret' => 'meta-test-secret',
                'is_enabled' => true,
            ]
        );

        $this->googleAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'external_account_id' => '1234567890',
            'account_name' => 'Google Test Account',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'access_token' => 'mock_google_token',
            'status' => 'active',
        ]);

        $this->tiktokAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'tiktok',
            'external_account_id' => 'tt_987654321',
            'account_name' => 'TikTok Test Account',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'access_token' => 'mock_tiktok_token',
            'status' => 'active',
        ]);

        $this->metaAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_5544332211',
            'account_name' => 'Meta Test Account',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'access_token' => 'mock_meta_token',
            'status' => 'active',
        ]);
    }

    public function test_google_ads_discovers_native_currency_and_timezone(): void
    {
        Http::fake([
            'googleads.googleapis.com/v18/customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9876543210'],
            ], 200),
            'googleads.googleapis.com/v18/customers/9876543210/googleAds:search' => Http::response([
                'results' => [
                    [
                        'customer' => [
                            'id' => '9876543210',
                            'descriptiveName' => 'Berlin Flagship Store',
                            'currencyCode' => 'EUR',
                            'timeZone' => 'Europe/Berlin',
                            'status' => 'ENABLED',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $adapter = new GoogleAdsAdapter();
        $accounts = $adapter->discoverAccounts('mock_access_token');

        $this->assertCount(1, $accounts);
        $this->assertEquals('9876543210', $accounts[0]['external_account_id']);
        $this->assertEquals('Berlin Flagship Store', $accounts[0]['account_name']);
        // Assert currency and timezone are NOT hardcoded USD/UTC
        $this->assertEquals('EUR', $accounts[0]['currency']);
        $this->assertEquals('Europe/Berlin', $accounts[0]['timezone']);
    }

    public function test_google_ads_pagination_across_multiple_pages_and_empty_final_page(): void
    {
        Http::fake([
            // Page 1
            'googleads.googleapis.com/v18/customers/1234567890/googleAds:search' => function ($request) {
                $body = json_decode($request->body(), true);
                $query = $body['query'] ?? '';

                if (str_contains($query, 'FROM ad_group_ad')) {
                    return Http::response(['results' => [], 'nextPageToken' => null], 200);
                }

                if (!isset($body['pageToken'])) {
                    return Http::response([
                        'results' => [
                            ['campaign' => ['id' => 'camp_g_1', 'name' => 'Campaign Page 1 - Item A', 'status' => 'ENABLED']],
                            ['campaign' => ['id' => 'camp_g_2', 'name' => 'Campaign Page 1 - Item B', 'status' => 'ENABLED']],
                        ],
                        'nextPageToken' => 'token_for_page_2',
                    ], 200);
                }

                if ($body['pageToken'] === 'token_for_page_2') {
                    return Http::response([
                        'results' => [
                            ['campaign' => ['id' => 'camp_g_3', 'name' => 'Campaign Page 2 - Item C', 'status' => 'ENABLED']],
                        ],
                        'nextPageToken' => null, // empty final page token
                    ], 200);
                }

                return Http::response(['results' => []], 200);
            },
        ]);

        $adapter = new GoogleAdsAdapter();
        $res = $adapter->fetchCampaigns($this->googleAccount);

        $this->assertCount(3, $res['campaigns'], "Pagination should collect all 3 campaigns across pages.");
        $ids = collect($res['campaigns'])->pluck('external_campaign_id')->all();
        $this->assertEquals(['camp_g_1', 'camp_g_2', 'camp_g_3'], $ids);
    }

    public function test_tiktok_ads_pagination_across_multiple_pages(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/adgroup/get/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [], 'page_info' => ['total_page' => 1]],
            ], 200),
            'business-api.tiktok.com/open_api/v1.3/ad/get/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [], 'page_info' => ['total_page' => 1]],
            ], 200),
            'business-api.tiktok.com/open_api/v1.3/campaign/get/*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                if ($page === 1) {
                    return Http::response([
                        'code' => 0,
                        'data' => [
                            'list' => [
                                ['campaign_id' => 'tt_camp_1', 'campaign_name' => 'TikTok Page 1 Camp A', 'operation_status' => 'ENABLE'],
                                ['campaign_id' => 'tt_camp_2', 'campaign_name' => 'TikTok Page 1 Camp B', 'operation_status' => 'ENABLE'],
                            ],
                            'page_info' => [
                                'page' => 1,
                                'page_size' => 100,
                                'total_page' => 2,
                                'total_number' => 3,
                            ],
                        ],
                    ], 200);
                }

                if ($page === 2) {
                    return Http::response([
                        'code' => 0,
                        'data' => [
                            'list' => [
                                ['campaign_id' => 'tt_camp_3', 'campaign_name' => 'TikTok Page 2 Camp C', 'operation_status' => 'ENABLE'],
                            ],
                            'page_info' => [
                                'page' => 2,
                                'page_size' => 100,
                                'total_page' => 2,
                                'total_number' => 3,
                            ],
                        ],
                    ], 200);
                }

                return Http::response(['code' => 0, 'data' => ['list' => []]], 200);
            },
        ]);

        $adapter = new TikTokAdsAdapter();
        $res = $adapter->fetchCampaigns($this->tiktokAccount);

        $this->assertCount(3, $res['campaigns'], "TikTok pagination should traverse both pages to return 3 campaigns.");
        $ids = collect($res['campaigns'])->pluck('external_campaign_id')->all();
        $this->assertEquals(['tt_camp_1', 'tt_camp_2', 'tt_camp_3'], $ids);
    }

    public function test_meta_ads_pagination_across_multiple_pages(): void
    {
        $callCount = 0;
        Http::fake([
            'graph.facebook.com/v21.0/act_5544332211/campaigns*' => function ($request) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response([
                        'data' => [
                            ['id' => 'meta_c_1', 'name' => 'Meta Camp 1', 'status' => 'ACTIVE'],
                            ['id' => 'meta_c_2', 'name' => 'Meta Camp 2', 'status' => 'ACTIVE'],
                        ],
                        'paging' => [
                            'next' => 'https://graph.facebook.com/v21.0/act_5544332211/campaigns?after=cursor_page_2',
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [
                        ['id' => 'meta_c_3', 'name' => 'Meta Camp 3', 'status' => 'ACTIVE'],
                    ],
                    'paging' => [
                        'cursors' => ['after' => 'cursor_page_2'],
                    ],
                ], 200);
            },
        ]);

        $adapter = new MetaAdsAdapter();
        $res = $adapter->fetchCampaigns($this->metaAccount);

        $this->assertCount(3, $res['campaigns'], "Meta pagination should traverse paging.next URL.");
        $ids = collect($res['campaigns'])->pluck('external_campaign_id')->all();
        $this->assertEquals(['meta_c_1', 'meta_c_2', 'meta_c_3'], $ids);
    }

    public function test_hierarchical_metric_storage_prevents_overwrites_between_entities(): void
    {
        $campaign = Campaign::create([
            'connected_ad_account_id' => $this->metaAccount->id,
            'provider' => 'meta',
            'external_campaign_id' => 'hier_camp_1',
            'name' => 'Hierarchical Campaign',
            'status' => 'ACTIVE',
        ]);

        $adSet = AdSet::create([
            'campaign_id' => $campaign->id,
            'external_ad_set_id' => 'hier_adset_1',
            'name' => 'Hierarchical Ad Set',
            'status' => 'ACTIVE',
        ]);

        $ad = Ad::create([
            'ad_set_id' => $adSet->id,
            'external_ad_id' => 'hier_ad_1',
            'name' => 'Hierarchical Ad',
            'status' => 'ACTIVE',
        ]);

        $mockAdapter = Mockery::mock(GoogleAdsAdapter::class);
        $mockAdapter->shouldReceive('fetchCampaigns')->andReturn([
            'endpoint' => '/campaigns',
            'raw' => [],
            'campaigns' => [],
        ]);

        // Insights returning metrics at 3 distinct levels on the same date:
        // 1. Campaign level
        // 2. AdSet level
        // 3. Ad level
        $mockAdapter->shouldReceive('fetchInsights')->andReturn([
            'endpoint' => '/insights',
            'raw' => [],
            'metrics' => [
                [
                    'external_campaign_id' => 'hier_camp_1',
                    'external_ad_set_id' => null,
                    'external_ad_id' => null,
                    'date' => '2026-11-19',
                    'spend' => 300.0,
                    'impressions' => 3000,
                    'clicks' => 300,
                    'conversions' => 30.0,
                    'conversion_value' => 900.0,
                ],
                [
                    'external_campaign_id' => 'hier_camp_1',
                    'external_ad_set_id' => 'hier_adset_1',
                    'external_ad_id' => null,
                    'date' => '2026-11-19',
                    'spend' => 200.0,
                    'impressions' => 2000,
                    'clicks' => 200,
                    'conversions' => 20.0,
                    'conversion_value' => 600.0,
                ],
                [
                    'external_campaign_id' => 'hier_camp_1',
                    'external_ad_set_id' => 'hier_adset_1',
                    'external_ad_id' => 'hier_ad_1',
                    'date' => '2026-11-19',
                    'spend' => 100.0,
                    'impressions' => 1000,
                    'clicks' => 100,
                    'conversions' => 10.0,
                    'conversion_value' => 300.0,
                ],
            ],
        ]);

        $mockOAuth = Mockery::mock(OAuthManager::class);
        $mockOAuth->shouldReceive('getAdapter')->with('meta')->andReturn($mockAdapter);

        $engine = new AdDataSyncEngine($mockOAuth);
        $syncRun = $engine->syncAccount($this->metaAccount, 'manual', '2026-11-19', '2026-11-19');

        $this->assertEquals('completed', $syncRun->status);

        // Verify that 3 distinct rows are stored without overwriting each other
        $metrics = PerformanceMetric::where('connected_ad_account_id', $this->metaAccount->id)
            ->where('date', '2026-11-19')
            ->get();

        $this->assertCount(3, $metrics, "There must be exactly 3 distinct metric rows for campaign, adset, and ad levels.");

        $campMetric = $metrics->firstWhere('ad_set_id', null);
        $this->assertNotNull($campMetric);
        $this->assertEquals(300.0, (float) $campMetric->spend);

        $adSetMetric = $metrics->first(fn($m) => $m->ad_set_id === $adSet->id && $m->ad_id === null);
        $this->assertNotNull($adSetMetric);
        $this->assertEquals(200.0, (float) $adSetMetric->spend);

        $adMetric = $metrics->firstWhere('ad_id', $ad->id);
        $this->assertNotNull($adMetric);
        $this->assertEquals(100.0, (float) $adMetric->spend);
    }
}
