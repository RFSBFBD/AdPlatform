<?php
namespace Tests\Feature;

use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TaboolaIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    public function test_non_admin_cannot_use_shared_client_credentials(): void
    {
        $u = User::factory()->create();
        Http::preventStrayRequests();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/integrations/taboola/credential-discovery')->assertForbidden();
        Http::assertNothingSent();
    }
    public function test_admin_discovers_then_imports_only_selected_account(): void
    {
        $u = User::factory()->create();
        $p = Permission::firstOrCreate(['name' => 'manage-developers', 'guard_name' => 'web']);
        $u->givePermissionTo($p);
        ProviderDeveloperConfig::updateOrCreate(['provider' => 'taboola'], ['app_id' => 'fixture-id', 'app_secret' => 'fixture-secret', 'is_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'taboola-fixture-token', 'expires_in' => 43200]),
            '*/allowed-accounts' => Http::response(['results' => [['account_id' => 't-fixture-1', 'name' => 'Fixture', 'currency' => 'EUR', 'time_zone_name' => 'Europe/Paris']]]),
        ]);
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/integrations/taboola/credential-discovery')->assertOk();
        $this->assertStringNotContainsString('taboola-fixture-token', $r->getContent());
        $this->postJson('/api/v1/integrations/taboola/connect', ['selected_account_ids' => ['t-fixture-1']])->assertOk();
        $this->assertDatabaseHas('connected_ad_accounts', ['user_id' => $u->id, 'provider' => 'taboola', 'external_account_id' => 't-fixture-1', 'currency' => 'EUR']);
    }
}
