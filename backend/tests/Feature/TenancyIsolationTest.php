<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userA;
    protected User $userB;
    protected User $viewerUser;
    protected ConnectedAdAccount $accountA;
    protected ConnectedAdAccount $accountB;
    protected Campaign $campaignA;
    protected Campaign $campaignB;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        (new RoleAndPermissionSeeder())->run(); // direct run: avoids artisan in php-wasm

        // User A (Analyst / Regular tenant user)
        $this->userA = User::create([
            'name' => 'Tenant User A',
            'email' => 'tenant_a_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
        $this->userA->assignRole('Owner'); // Owner of their tenant

        // User B (Separate tenant user)
        $this->userB = User::create([
            'name' => 'Tenant User B',
            'email' => 'tenant_b_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);

        // Viewer User (Cannot manage accounts)
        $this->viewerUser = User::create([
            'name' => 'Viewer Only',
            'email' => 'viewer_' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'status' => 'active',
        ]);
        $this->viewerUser->assignRole('Viewer');

        // Account A belonging to User A (USD)
        $this->accountA = ConnectedAdAccount::create([
            'user_id' => $this->userA->id,
            'provider' => 'meta',
            'external_account_id' => 'act_tenant_a_' . uniqid(),
            'account_name' => 'Tenant A Meta Store',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'token_tenant_a',
            'status' => 'connected',
        ]);

        // Account B belonging to User B (EUR)
        $this->accountB = ConnectedAdAccount::create([
            'user_id' => $this->userB->id,
            'provider' => 'google',
            'external_account_id' => 'act_tenant_b_' . uniqid(),
            'account_name' => 'Tenant B Google Search',
            'currency' => 'EUR',
            'timezone' => 'UTC',
            'access_token' => 'token_tenant_b',
            'status' => 'connected',
        ]);

        // Campaigns
        $this->campaignA = Campaign::create([
            'connected_ad_account_id' => $this->accountA->id,
            'provider' => 'meta',
            'external_campaign_id' => 'camp_a_' . uniqid(),
            'name' => 'Tenant A Holiday Campaign',
            'status' => 'ACTIVE',
            'currency' => 'USD',
        ]);

        $this->campaignB = Campaign::create([
            'connected_ad_account_id' => $this->accountB->id,
            'provider' => 'google',
            'external_campaign_id' => 'camp_b_' . uniqid(),
            'name' => 'Tenant B Brand Search',
            'status' => 'ACTIVE',
            'currency' => 'EUR',
        ]);

        // Performance Metrics
        PerformanceMetric::create([
            'connected_ad_account_id' => $this->accountA->id,
            'campaign_id' => $this->campaignA->id,
            'provider' => 'meta',
            'date' => now()->format('Y-m-d'),
            'spend' => 100.00,
            'impressions' => 10000,
            'clicks' => 500,
            'conversions' => 25,
            'conversion_value' => 500.00,
        ]);

        PerformanceMetric::create([
            'connected_ad_account_id' => $this->accountB->id,
            'campaign_id' => $this->campaignB->id,
            'provider' => 'google',
            'date' => now()->format('Y-m-d'),
            'spend' => 250.00,
            'impressions' => 20000,
            'clicks' => 800,
            'conversions' => 40,
            'conversion_value' => 1200.00,
        ]);

    }

    public function test_user_cannot_list_ad_accounts_belonging_to_another_user(): void
    {
        $responseA = $this->actingAs($this->userA, 'sanctum')->getJson('/api/v1/ad-accounts');
        $responseA->assertOk();
        $accountNamesA = collect($responseA->json('data'))->pluck('account_name');
        $this->assertTrue($accountNamesA->contains('Tenant A Meta Store'));
        $this->assertFalse($accountNamesA->contains('Tenant B Google Search'));

        $responseB = $this->actingAs($this->userB, 'sanctum')->getJson('/api/v1/ad-accounts');
        $responseB->assertOk();
        $accountNamesB = collect($responseB->json('data'))->pluck('account_name');
        $this->assertTrue($accountNamesB->contains('Tenant B Google Search'));
        $this->assertFalse($accountNamesB->contains('Tenant A Meta Store'));
    }

    public function test_analytics_overview_is_strictly_scoped_to_user_connected_accounts(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/v1/analytics/overview');
        $response->assertOk();

        // User A spend must be exactly 100.00, NOT 350.00 (which would be 100 + 250)
        $this->assertEquals(100.00, $response->json('summary.spend'));
        $this->assertEquals(500.00, $response->json('summary.conversion_value'));

        // DATA-001: Currency breakdown must report USD only for User A
        $currencies = collect($response->json('currency_breakdown'))->pluck('currency');
        $this->assertTrue($currencies->contains('USD'));
        $this->assertFalse($currencies->contains('EUR'));
    }

    public function test_campaigns_endpoint_only_returns_campaigns_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/v1/analytics/campaigns');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Tenant A Holiday Campaign'));
        $this->assertFalse($names->contains('Tenant B Brand Search'));
    }

    public function test_campaign_details_rejects_access_to_another_users_campaign(): void
    {
        // User B tries to access Campaign A
        $response = $this->actingAs($this->userB, 'sanctum')->getJson("/api/v1/analytics/campaigns/{$this->campaignA->id}");
        $response->assertStatus(403);
    }

    public function test_viewer_role_cannot_disconnect_an_ad_account_sec_002(): void
    {
        $response = $this->actingAs($this->viewerUser, 'sanctum')->deleteJson("/api/v1/ad-accounts/{$this->accountA->id}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('connected_ad_accounts', ['id' => $this->accountA->id]);
    }

    public function test_user_cannot_disconnect_an_ad_account_belonging_to_another_user(): void
    {
        // User B tries to disconnect Account A
        $response = $this->actingAs($this->userB, 'sanctum')->deleteJson("/api/v1/ad-accounts/{$this->accountA->id}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('connected_ad_accounts', ['id' => $this->accountA->id]);
    }

    public function test_owner_can_disconnect_own_ad_account(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->deleteJson("/api/v1/ad-accounts/{$this->accountA->id}");
        $response->assertOk();
        $this->assertDatabaseMissing('connected_ad_accounts', ['id' => $this->accountA->id]);
    }

    public function test_regular_user_cannot_disconnect_or_sync_account_with_null_user_id(): void
    {
        $unassignedAccount = ConnectedAdAccount::create([
            'user_id' => null,
            'provider' => 'meta',
            'external_account_id' => 'act_null_owner_999',
            'account_name' => 'Legacy Unassigned Account',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'dummy_token',
            'status' => 'connected',
        ]);

        // Regular user B (who does not have Owner/Admin role) must be rejected with 403
        $responseDisconnect = $this->actingAs($this->userB, 'sanctum')
            ->deleteJson("/api/v1/ad-accounts/{$unassignedAccount->id}");
        $responseDisconnect->assertStatus(403);

        $responseSync = $this->actingAs($this->userB, 'sanctum')
            ->postJson("/api/v1/ad-accounts/{$unassignedAccount->id}/sync");
        $responseSync->assertStatus(403);

        $responseStatus = $this->actingAs($this->userB, 'sanctum')
            ->getJson("/api/v1/ad-accounts/{$unassignedAccount->id}/sync-status");
        $responseStatus->assertStatus(403);
    }

    public function test_owner_role_does_not_cross_the_tenant_boundary_on_account_endpoints(): void
    {
        // userA is an Owner of THEIR tenant; the Owner role must not grant access to userB's account.
        $this->assertTrue($this->userA->hasRole('Owner'));
        $id = $this->accountB->id;

        $this->actingAs($this->userA, 'sanctum')->deleteJson("/api/v1/ad-accounts/{$id}")->assertStatus(403);
        $this->actingAs($this->userA, 'sanctum')->postJson("/api/v1/ad-accounts/{$id}/sync")->assertStatus(403);
        $this->actingAs($this->userA, 'sanctum')->getJson("/api/v1/ad-accounts/{$id}/sync-status")->assertStatus(403);
        $this->actingAs($this->userA, 'sanctum')->getJson("/api/v1/ad-accounts/{$id}/sync-history")->assertStatus(403);
        $this->actingAs($this->userA, 'sanctum')->getJson("/api/v1/analytics/campaigns/{$this->campaignB->id}")->assertStatus(403);

        $this->assertNotNull(ConnectedAdAccount::find($id));
    }
}
