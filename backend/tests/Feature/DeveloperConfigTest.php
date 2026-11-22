<?php

namespace Tests\Feature;

use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeveloperConfigTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        (new RoleAndPermissionSeeder())->run(); // direct run: avoids artisan in php-wasm
    }

    public function test_unauthorized_user_cannot_read_developer_configs(): void
    {
        $response = $this->getJson('/api/v1/admin/developer-configs');
        $response->assertStatus(401);
    }

    public function test_analyst_cannot_access_developer_configs(): void
    {
        $analyst = User::firstOrCreate(
            ['email' => 'analyst_dev@example.com'],
            [
                'name' => 'Analyst User',
                'password' => bcrypt('Password123!'),
                'status' => 'active',
            ]
        );
        if (!$analyst->hasRole('Analyst')) {
            $analyst->assignRole('Analyst');
        }

        $token = $analyst->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/v1/admin/developer-configs');

        $response->assertStatus(403);
    }

    public function test_owner_can_configure_meta_credentials_and_secrets_are_encrypted(): void
    {
        $owner = User::where('email', 'admin@adplatform.local')->first();
        $token = $owner->createToken('token')->plainTextToken;

        $rawSecret = 'super_secret_meta_marketing_api_key_9988';

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->putJson('/api/v1/admin/developer-configs/meta', [
                             'app_id' => '123456789012345',
                             'app_secret' => $rawSecret,
                             'redirect_uri' => 'https://adplatform.local/oauth/meta/callback',
                         ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'provider' => 'meta',
                         'app_id' => '123456789012345',
                         'has_secret' => true,
                         'masked_secret' => '••••••••9988',
                     ],
                 ]);

        // CRITICAL: Ensure raw secret is NEVER in API response
        $this->assertStringNotContainsString($rawSecret, $response->getContent());

        // Verify encryption at rest in PostgreSQL
        $rawDbRow = DB::table('provider_developer_configs')->where('provider', 'meta')->first();
        $this->assertNotNull($rawDbRow);
        $this->assertNotEquals($rawSecret, $rawDbRow->app_secret);
        $this->assertStringNotContainsString($rawSecret, $rawDbRow->app_secret);

        // Verify Eloquent model decrypts properly
        $eloquentModel = ProviderDeveloperConfig::where('provider', 'meta')->first();
        $this->assertEquals($rawSecret, $eloquentModel->app_secret);
    }
}
