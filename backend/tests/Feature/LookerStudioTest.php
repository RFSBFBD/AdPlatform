<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LookerStudioTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected ConnectedAdAccount $account;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'looker_tester@example.com'],
            ['name' => 'Looker Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        $this->account = ConnectedAdAccount::create([
            'user_id' => $this->user->id,
            'provider' => 'meta',
            'external_account_id' => 'act_looker_111',
            'account_name' => 'Global Ecommerce US',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'mock_looker_token',
            'status' => 'active',
        ]);

        $this->campaign = Campaign::create([
            'connected_ad_account_id' => $this->account->id,
            'provider' => 'meta',
            'external_campaign_id' => 'looker_camp_1',
            'name' => 'Holiday Sales 2026',
            'status' => 'ACTIVE',
            'currency' => 'USD',
        ]);

        PerformanceMetric::create([
            'connected_ad_account_id' => $this->account->id,
            'campaign_id' => $this->campaign->id,
            'provider' => 'meta',
            'date' => '2026-11-18',
            'spend' => 450.00,
            'impressions' => 25000,
            'clicks' => 1200,
            'conversions' => 48,
            'conversion_value' => 2160.00,
            'cpc' => 0.38,
            'cpm' => 18.00,
            'ctr' => 4.80,
            'roas' => 4.80,
            'cpa' => 9.38,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/reporting/looker-studio')->assertStatus(401);
    }

    public function test_schema_request_returns_looker_dimensions_and_metrics(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reporting/looker-studio?schema=true');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'schema' => [
                    '*' => ['name', 'label', 'dataType', 'semantics'],
                ],
            ]);

        $names = collect($response->json('schema'))->pluck('name')->toArray();
        $this->assertContains('date', $names);
        $this->assertContains('provider', $names);
        $this->assertContains('account_name', $names);
        $this->assertContains('campaign_name', $names);
        $this->assertContains('spend', $names);
        $this->assertContains('roas', $names);
    }

    public function test_data_request_returns_aggregated_rows_for_looker(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reporting/looker-studio?start_date=2026-11-01&end_date=2026-11-20');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'schema',
                'rows' => [
                    '*' => ['values'],
                ],
            ]);

        $rows = $response->json('rows');
        $this->assertNotEmpty($rows);

        $firstRow = $rows[0]['values'];
        // Looker date format YYYYMMDD
        $this->assertEquals('20261118', $firstRow[0]);
        $this->assertEquals('meta', $firstRow[1]);
        $this->assertEquals('Global Ecommerce US', $firstRow[2]);
        $this->assertEquals('Holiday Sales 2026', $firstRow[3]);
        $this->assertEquals('USD', $firstRow[4]);
        $this->assertEquals(450.00, $firstRow[5]); // spend
    }

    public function test_multi_tenant_isolation_prevents_user_from_seeing_another_users_metrics(): void
    {
        $otherUser = User::firstOrCreate(
            ['email' => 'other_looker_user@example.com'],
            ['name' => 'Other Looker User', 'password' => bcrypt('password123'), 'status' => 'active']
        );

        // User A (owner) should see 1 row
        $responseA = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reporting/looker-studio?start_date=2026-11-01&end_date=2026-11-20');
        $responseA->assertStatus(200);
        $this->assertNotEmpty($responseA->json('rows'));

        // User B (non-owner) must NOT see User A's rows
        $responseB = $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/reporting/looker-studio?start_date=2026-11-01&end_date=2026-11-20');
        $responseB->assertStatus(200);
        $this->assertEmpty($responseB->json('rows'));
    }
}
