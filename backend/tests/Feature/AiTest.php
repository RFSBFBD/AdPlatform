<?php

namespace Tests\Feature;

use App\Models\AiAuditLog;
use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AiTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'ai_tester@example.com'],
            ['name' => 'AI Tester', 'password' => bcrypt('password123'), 'status' => 'active']
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/ai/config')->assertStatus(401);
        $this->postJson('/api/v1/ai/generate-copy')->assertStatus(401);
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

    public function test_generate_ad_copy_enforces_limits_and_audits_usage(): void
    {
        AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'openai',
            'api_key' => 'sk-mock-key-for-testing',
            'model' => 'gpt-4o',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/ai/generate-copy', [
                'platform' => 'meta',
                'product_name' => 'Ergonomic Standing Desk',
                'objective' => 'Conversions',
                'tone' => 'Professional & Persuasive',
                'target_audience' => 'Remote professionals',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'copy' => ['headlines', 'primary_texts', 'descriptions', 'call_to_action'],
                'usage' => ['tokens', 'model', 'provider'],
            ]);

        // Verify audit log recorded
        $log = AiAuditLog::where('user_id', $this->user->id)
            ->where('action', 'generate_copy')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertGreaterThan(0, $log->total_tokens);
    }

    public function test_analyze_performance_returns_recommendations(): void
    {
        AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'openai',
            'api_key' => 'sk-mock-key-for-testing',
            'model' => 'gpt-4o',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/ai/analyze-performance', [
                'metrics' => [
                    'campaign_name' => 'Black Friday Retargeting',
                    'spend' => 1500.00,
                    'conversions' => 45,
                    'roas' => 3.2,
                    'ctr' => 1.8,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'analysis' => ['summary', 'strengths', 'concerns', 'recommendations'],
                'usage' => ['tokens'],
            ]);
    }
}
