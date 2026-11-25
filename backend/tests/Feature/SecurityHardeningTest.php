<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutboundUrlGuard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Local, synthetic-only checks. No provider or model is contacted. */
class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    private function userWith(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_admin_cannot_promote_demote_or_delete_owner(): void
    {
        $owner = $this->userWith('Owner');
        $admin = $this->userWith('Admin');
        $viewer = $this->userWith('Viewer');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$viewer->id}/role", ['role' => 'Owner'])->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$owner->id}/role", ['role' => 'Viewer'])->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$owner->id}")->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'role' => 'Owner'])->assertStatus(403);

        $this->assertTrue($owner->fresh()->hasRole('Owner'));
        $this->assertTrue($viewer->fresh()->hasRole('Viewer'));
    }

    public function test_last_owner_cannot_be_demoted_or_deleted_but_a_second_owner_can_be(): void
    {
        User::role('Owner')->get()->each(fn ($u) => $u->removeRole('Owner'));
        $a = $this->userWith('Owner');
        $b = $this->userWith('Owner');

        // b demotes a: allowed while two Owners exist
        $this->actingAs($b, 'sanctum')->putJson("/api/v1/users/{$a->id}/role", ['role' => 'Admin'])->assertOk();
        // a is now Admin; b is the last Owner and cannot be removed by itself or by a
        $this->actingAs($a, 'sanctum')->deleteJson("/api/v1/users/{$b->id}")->assertStatus(403);
        $this->assertSame(1, User::role('Owner')->count());
    }

    public function test_viewer_and_analyst_cannot_write_ai_config_or_business_profile(): void
    {
        foreach (['Viewer', 'Analyst'] as $role) {
            $u = $this->userWith($role);
            $this->actingAs($u, 'sanctum')->putJson('/api/v1/ai/config/openai', ['api_key' => 'k-synthetic-1'])->assertStatus(403);
            $this->actingAs($u, 'sanctum')->deleteJson('/api/v1/ai/config/openai')->assertStatus(403);
            $this->actingAs($u, 'sanctum')->postJson('/api/v1/ai/config/openai/test')->assertStatus(403);
            $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', ['business_name' => 'X'])->assertStatus(403);
            // reads stay allowed
            $this->actingAs($u, 'sanctum')->getJson('/api/v1/ai/config')->assertOk();
            $this->actingAs($u, 'sanctum')->getJson('/api/v1/business-profile')->assertOk();
        }
        $admin = $this->userWith('Admin');
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/business-profile', ['business_name' => 'Synthetic Co'])->assertOk();
    }

    public function test_ai_base_url_rejects_internal_and_non_https_targets(): void
    {
        $admin = $this->userWith('Admin');
        foreach ([
            'http://example.com/v1', 'https://localhost/v1', 'https://127.0.0.1/v1', 'https://10.0.0.5/v1',
            'https://192.168.1.1/v1', 'https://169.254.169.254/latest', 'https://[::1]/v1', 'https://[fd00::1]/v1',
            'https://100.64.0.1/v1', 'https://user:pw@8.8.8.8/v1', 'https://8.8.8.8:8443/v1', 'file:///etc/passwd',
            'https://[::ffff:127.0.0.1]/v1', 'https://svc.internal/v1',
        ] as $url) {
            $this->actingAs($admin, 'sanctum')
                ->putJson('/api/v1/ai/config/custom', ['api_key' => 'k-synthetic-1', 'base_url' => $url])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'AI_BASE_URL_NOT_ALLOWED');
        }
    }

    public function test_guard_accepts_public_ip_literal_and_classifies_ranges(): void
    {
        $this->assertSame('8.8.8.8', OutboundUrlGuard::assertPublicHttps('https://8.8.8.8/v1')['ip']);
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.0.1', '169.254.169.254', '100.64.1.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1'] as $ip) {
            $this->assertFalse(OutboundUrlGuard::isPublicIp($ip), $ip);
        }
        $this->assertTrue(OutboundUrlGuard::isPublicIp('1.1.1.1'));
    }

    public function test_stored_internal_base_url_is_refused_at_use_time_without_any_request(): void
    {
        $admin = $this->userWith('Admin');
        \App\Models\AiConfig::create([
            'user_id' => $admin->id, 'provider' => 'custom', 'api_key' => 'k-synthetic-1',
            'model' => 'm', 'is_active' => true, 'metadata' => ['base_url' => 'http://127.0.0.1:9/v1'],
        ]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ai/config/custom/test')->assertStatus(502);
        Http::assertNothingSent();
    }

    public function test_snapchat_rows_do_not_block_all_channels_but_stay_visible_per_provider(): void
    {
        $u = $this->userWith('Owner');
        $acc = \App\Models\ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'snapchat', 'external_account_id' => uniqid(), 'account_name' => 'Synthetic Snap', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture']);
        $c = \App\Models\Campaign::create(['connected_ad_account_id' => $acc->id, 'provider' => 'snapchat', 'external_campaign_id' => uniqid(), 'name' => 'Synthetic Snap Campaign', 'currency' => 'USD']);
        \App\Models\PerformanceMetric::create(['connected_ad_account_id' => $acc->id, 'campaign_id' => $c->id, 'provider' => 'snapchat', 'date' => now()->subDay()->toDateString(), 'spend' => 5, 'impressions' => 10, 'clicks' => 1, 'conversions' => 0, 'conversion_value' => 0, 'currency' => 'USD']);

        $all = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/overview')->assertOk();
        $all->assertJsonPath('excluded_providers.0.provider', 'snapchat');
        $this->assertEquals(0, $all->json('summary.spend'));
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/campaigns')->assertOk()->assertJsonCount(0, 'data');
        // per-provider view keeps the existing explicit refusal
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/overview?provider=snapchat')->assertStatus(409);
    }
}
