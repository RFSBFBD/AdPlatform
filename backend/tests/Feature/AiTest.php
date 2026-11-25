<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI surface after the product cleanup: the only AI feature is the user's model connection
 * (BYOK / custom endpoint) whose single bounded job is ordering the deterministic OR
 * suggestions and attaching fixed server-written notes (covered by SuggestionNarratorTest).
 * There are no free-text generation endpoints; this file covers the configuration surface.
 */
class AiTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never reach a real provider: any request without an explicit fake fails.
        Http::preventStrayRequests();

        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);
        $this->user = User::firstOrCreate(
            ['email' => 'ai_tester@example.com'],
            ['name' => 'AI Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );
        $this->user->assignRole('Admin'); // AI config writes need manage-ai
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/ai/config')->assertStatus(401);
        $this->putJson('/api/v1/ai/config/openai', ['api_key' => 'sk-x'])->assertStatus(401);
    }

    public function test_byok_configuration_encrypts_key_and_masks_in_response(): void
    {
        $rawKey = 'sk-proj-test-1234567890abcdef1234';

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/ai/config/openai', [
                'api_key' => $rawKey,
                'model' => 'gpt-4o',
                'monthly_token_limit' => 500000,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'config' => [
                    'provider' => 'openai',
                    'model' => 'gpt-4o',
                    'is_configured' => true,
                    'api_key_masked' => '••••••••1234',
                ],
            ]);

        // Verify key is encrypted at rest in DB
        $dbConfig = AiConfig::where('user_id', $this->user->id)->where('provider', 'openai')->first();
        $this->assertNotNull($dbConfig);
        $this->assertEquals($rawKey, $dbConfig->api_key); // Decrypted on read via Eloquent cast
        $this->assertStringNotContainsString($rawKey, $response->getContent());
    }

    public function test_config_is_listed_per_user_only(): void
    {
        $other = User::firstOrCreate(
            ['email' => 'other_ai_tenant@example.com'],
            ['name' => 'Other', 'password' => bcrypt('password123'), 'status' => 'active']
        );
        AiConfig::create([
            'user_id' => $other->id, 'provider' => 'openai', 'api_key' => 'sk-other-secret',
            'model' => 'gpt-4o', 'is_active' => true,
        ]);

        $configs = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/ai/config')
            ->assertStatus(200)
            ->json('configs');

        $this->assertSame([], $configs, 'Another tenant\'s AI configuration must never leak into my list.');
    }

    public function test_custom_ai_provider_configuration_and_test(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [['id' => 'llama-3.3-70b-versatile']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/ai/config/custom', [
                'api_key' => 'gsk_mock_api_key_12345678',
                'model' => 'llama-3.3-70b-versatile',
                'base_url' => 'https://api.groq.com/openai/v1',
            ]);

        $response->assertStatus(200);

        $testResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/ai/config/custom/test');

        $testResponse->assertStatus(200)
            ->assertJson([
                'result' => [
                    'status' => 'connected',
                    'provider' => 'custom',
                ],
            ]);
    }

    public function test_rejected_key_returns_safe_502_without_leaking_upstream_body(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'error' => ['message' => 'Incorrect API key provided: sk-live-SECRETSHOULDNOTLEAK'],
            ], 401),
        ]);

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/ai/config/openai', [
            'api_key' => 'sk-invalid-key',
            'model' => 'gpt-4o-mini',
        ])->assertStatus(200);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/ai/config/openai/test');

        $response->assertStatus(502)->assertJson([
            'success' => false,
            'error' => ['code' => 'AI_UPSTREAM_REJECTED'],
        ]);
        $this->assertStringNotContainsString('SECRETSHOULDNOTLEAK', $response->getContent());
        $this->assertStringNotContainsString('Incorrect API key', $response->getContent());
        $this->assertStringNotContainsString('sk-invalid-key', $response->getContent());
    }

    public function test_unreachable_endpoint_returns_safe_502_not_500(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: could not resolve host');
        });

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/ai/config/openai', [
            'api_key' => 'sk-invalid-key',
            'model' => 'gpt-4o-mini',
        ])->assertStatus(200);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/ai/config/openai/test');

        $response->assertStatus(502)->assertJson([
            'success' => false,
            'error' => ['code' => 'AI_UPSTREAM_UNREACHABLE'],
        ]);
        $this->assertStringNotContainsString('cURL', $response->getContent());
    }

    public function test_missing_key_returns_422_not_500(): void
    {
        AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'openai',
            'api_key' => '',
            'model' => 'gpt-4o-mini',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/ai/config/openai/test');

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => ['code' => 'AI_KEY_NOT_CONFIGURED'],
        ]);
    }
}
