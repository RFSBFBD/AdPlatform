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
        PerformanceMetric::create([
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

        PerformanceMetric::create([
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

        $response->assertStatus(200)
            ->assertJsonStructure([
                'date_range' => ['start_date', 'end_date'],
                'summary' => [
                    'spend', 'impressions', 'clicks', 'conversions', 'conversion_value',
                    'cpc', 'cpm', 'ctr', 'roas', 'cpa'
                ],
                'platforms',
                'timeline',
            ]);

        $data = $response->json();
        // Total Spend: 100 + 200 = 300
        $this->assertEquals(300.00, $data['summary']['spend']);
        // Total Clicks: 500 + 400 = 900
        $this->assertEquals(900, $data['summary']['clicks']);
        // Total Conversions: 20 + 10 = 30
        $this->assertEquals(30, $data['summary']['conversions']);
        // Total Conversion Value: 600 + 400 = 1000
        $this->assertEquals(1000.00, $data['summary']['conversion_value']);
        // Blended ROAS: 1000 / 300 = 3.33
        $this->assertEquals(3.33, $data['summary']['roas']);

        // Verify Platform breakdown has both Meta and Google
        $this->assertCount(2, $data['platforms']);
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
