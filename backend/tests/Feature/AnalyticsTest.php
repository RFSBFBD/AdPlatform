<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $metaAccount;
    protected ConnectedAdAccount $googleAccount;
    protected Campaign $metaCampaign;
    protected Campaign $googleCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'analytics_tester@example.com'],
            ['name' => 'Analytics Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        $this->metaAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_111222333',
            'account_name' => 'Meta Store UK',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'meta_token_123',
            'status' => 'active',
        ]);

        $this->googleAccount = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'external_account_id' => '9998887776',
            'account_name' => 'Google Search US',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'access_token' => 'google_token_123',
            'status' => 'active',
        ]);

        $this->metaCampaign = Campaign::create([
            'connected_ad_account_id' => $this->metaAccount->id,
            'provider' => 'meta',
            'external_campaign_id' => 'meta_camp_1',
            'name' => 'Retargeting Black Friday',
            'status' => 'ACTIVE',
            'daily_budget' => 100.00,
            'currency' => 'USD',
        ]);

        $this->googleCampaign = Campaign::create([
            'connected_ad_account_id' => $this->googleAccount->id,
            'provider' => 'google',
            'external_campaign_id' => 'google_camp_1',
            'name' => 'Search High Intent',
            'status' => 'ACTIVE',
            'daily_budget' => 200.00,
            'currency' => 'USD',
        ]);

        // Insert metrics
        PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],
            'connected_ad_account_id' => $this->metaAccount->id,
            'campaign_id' => $this->metaCampaign->id,
            'provider' => 'meta',
            'date' => '2026-11-15',
            'spend' => 100.00,
            'impressions' => 10000,
            'clicks' => 500,
            'conversions' => 20,
            'conversion_value' => 600.00,
            'cpc' => 0.20,
            'cpm' => 10.00,
            'ctr' => 5.00,
            'roas' => 6.00,
            'cpa' => 5.00,
        ]);

        PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'google','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],
            'connected_ad_account_id' => $this->googleAccount->id,
            'campaign_id' => $this->googleCampaign->id,
            'provider' => 'google',
            'date' => '2026-11-15',
            'spend' => 200.00,
            'impressions' => 4000,
            'clicks' => 400,
            'conversions' => 10,
            'conversion_value' => 400.00,
            'cpc' => 0.50,
            'cpm' => 50.00,
            'ctr' => 10.00,
            'roas' => 2.00,
            'cpa' => 20.00,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/analytics/overview')->assertStatus(401);
        $this->getJson('/api/v1/analytics/campaigns')->assertStatus(401);
    }

    public function test_overview_calculates_aggregated_and_blended_metrics(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/overview?start_date=2026-11-01&end_date=2026-11-20');

        $response->assertOk()->assertJsonPath('summary.spend',300)->assertJsonPath('summary.clicks',900)->assertJsonPath('summary.conversions',null)->assertJsonPath('summary.roas',null)->assertJsonPath('outcome_semantics.status','outcomes_withheld');
    }

    public function test_overview_filters_by_provider(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/overview?provider=meta&start_date=2026-11-01&end_date=2026-11-20');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(100.00, $data['summary']['spend']);
        $this->assertEquals(500, $data['summary']['clicks']);
        $this->assertEquals(6.00, $data['summary']['roas']);
        $this->assertCount(1, $data['platforms']);
        $this->assertEquals('meta', $data['platforms'][0]['provider']);
    }

    public function test_campaigns_endpoint_returns_paginated_list_with_metrics(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/analytics/campaigns?start_date=2026-11-01&end_date=2026-11-20');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'external_campaign_id', 'name', 'provider', 'status',
                        'daily_budget', 'account_name', 'metrics' => [
                            'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'roas'
                        ]
                    ]
                ],
                'pagination' => ['current_page', 'total'],
            ]);

        $items = collect($response->json('data'));
        $metaItem = $items->firstWhere('external_campaign_id', 'meta_camp_1');
        $this->assertNotNull($metaItem);
        $this->assertEquals('Retargeting Black Friday', $metaItem['name']);
        $this->assertEquals(100.00, $metaItem['metrics']['spend']);
        $this->assertEquals(6.00, $metaItem['metrics']['roas']);
    }

    public function test_campaign_details_endpoint(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/analytics/campaigns/{$this->metaCampaign->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'campaign' => ['id', 'name', 'connected_ad_account'],
                'timeline',
            ]);
    }
}
