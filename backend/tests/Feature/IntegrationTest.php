<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['oauth.allowed_redirect_uris' => ['https://adplatform.local/callback']]);
        (new RoleAndPermissionSeeder())->run(); // direct run: avoids artisan in php-wasm
        $this->owner = User::where('email', 'admin@adplatform.local')->first();
        $this->token = $this->owner->createToken('test_token')->plainTextToken;

        // Configure mock developer credentials for tests
        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'meta'],
            [
                'app_id' => '123456789012345',
                'app_secret' => 'meta_app_secret_test_9988',
                'is_enabled' => true,
            ]
        );

        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'google'],
            [
                'app_id' => 'google-client-id-test.apps.googleusercontent.com',
                'app_secret' => 'google_secret_test_9988',
                'developer_token' => 'dev_token_123',
                'is_enabled' => true,
            ]
        );

        ProviderDeveloperConfig::updateOrCreate(
            ['provider' => 'tiktok'],
            [
                'app_id' => 'tiktok_app_id_test_7766',
                'app_secret' => 'tiktok_secret_test_7766',
                'is_enabled' => true,
            ]
        );
    }

    public function test_meta_oauth_redirect_generates_valid_url_and_state(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
                         ->getJson('/api/v1/integrations/oauth/meta/redirect?redirect_uri=https://adplatform.local/callback');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => ['provider', 'auth_url', 'state'],
                 ]);

        $authUrl = $response->json('data.auth_url');
        $this->assertStringContainsString('https://www.facebook.com/v21.0/dialog/oauth', $authUrl);
        $this->assertStringContainsString('client_id=123456789012345', $authUrl);
        $this->assertStringContainsString('scope=ads_read', $authUrl);

        // Verify state is cached in Redis
        $state = $response->json('data.state');
        $this->assertTrue(Cache::has("oauth_state:{$state}"));
    }

    public function test_google_oauth_redirect_generates_valid_url_and_scopes(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
                         ->getJson('/api/v1/integrations/oauth/google/redirect?redirect_uri=https://adplatform.local/callback');

        $response->assertStatus(200);
        $authUrl = $response->json('data.auth_url');
        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $authUrl);
        $this->assertStringContainsString('access_type=offline', $authUrl);
        $this->assertStringContainsString('prompt=consent', $authUrl);
    }

    public function test_callback_rejects_invalid_or_expired_state(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
                         ->getJson('/api/v1/integrations/oauth/meta/callback?code=mock_code&state=non_existent_state&redirect_uri=https://adplatform.local/callback');

        $response->assertStatus(400)
                 ->assertJson([
                     'success' => false,
                     'error' => [
                         'code' => 'INVALID_OAUTH_STATE',
                     ],
                 ]);
    }

    public function test_connecting_ad_accounts_encrypts_tokens_and_masks_them(): void
    {
        $sessionKey = "discovered_accounts:meta:{$this->owner->id}";
        $rawAccessToken = 'EAA_super_secret_meta_user_access_token_12345';

        // Same shape the callback stores: encrypted JSON with ISO dates, safe for a database/redis cache.
        Cache::put($sessionKey, \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'token_data' => [
                'access_token' => $rawAccessToken,
                'refresh_token' => null,
                'expires_at' => now()->addDays(60)->format(DATE_ATOM),
            ],
            'accounts' => [
                [
                    'provider' => 'meta',
                    'external_account_id' => 'act_101202303',
                    'account_name' => 'Main Ecommerce Ad Account',
                    'currency' => 'USD',
                    'timezone' => 'America/New_York',
                ],
            ],
        ])), 600);
        $this->assertStringNotContainsString($rawAccessToken, (string) Cache::get($sessionKey));

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
                         ->postJson('/api/v1/integrations/meta/connect', [
                             'selected_account_ids' => ['act_101202303'],
                         ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         [
                             'provider' => 'meta',
                             'external_account_id' => 'act_101202303',
                             'account_name' => 'Main Ecommerce Ad Account',
                             'status' => 'connected',
                         ],
                     ],
                 ]);

        // CRITICAL: Ensure access token is NEVER returned in response
        $this->assertStringNotContainsString($rawAccessToken, $response->getContent());

        // Verify encryption in PostgreSQL
        $dbRow = DB::table('connected_ad_accounts')->where('external_account_id', 'act_101202303')->first();
        $this->assertNotNull($dbRow);
        $this->assertNotEquals($rawAccessToken, $dbRow->access_token);
        $this->assertStringNotContainsString($rawAccessToken, $dbRow->access_token);

        // Verify Eloquent decrypts properly
        $account = ConnectedAdAccount::where('external_account_id', 'act_101202303')->first();
        $this->assertEquals($rawAccessToken, $account->access_token);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ad_account.connected',
        ]);
    }

    public function test_connecting_account_already_owned_by_another_user_is_forbidden(): void
    {
        $otherUser = User::firstOrCreate(
            ['email' => 'other_connect_user@example.com'],
            ['name' => 'Other Connect User', 'password' => bcrypt('password123'), 'status' => 'active']
        );
        $otherToken = $otherUser->createToken('test')->plainTextToken;

        // User A connects the account
        ConnectedAdAccount::create([
            'user_id' => $this->owner->id,
            'provider' => 'meta',
            'external_account_id' => 'act_takeover_target_999',
            'account_name' => 'Protected Brand Account',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'access_token' => 'original_token',
            'status' => 'connected',
        ]);

        // User B attempts to connect the same account ID
        $sessionKey = "discovered_accounts:meta:{$otherUser->id}";
        Cache::put($sessionKey, [
            'token_data' => [
                'access_token' => 'attacker_token',
                'refresh_token' => null,
                'expires_at' => now()->addDays(60),
            ],
            'accounts' => [
                [
                    'provider' => 'meta',
                    'external_account_id' => 'act_takeover_target_999',
                    'account_name' => 'Protected Brand Account',
                    'currency' => 'USD',
                    'timezone' => 'UTC',
                ],
            ],
        ], 600);

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson('/api/v1/integrations/meta/connect', [
                'selected_account_ids' => ['act_takeover_target_999'],
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'ACCOUNT_ALREADY_CONNECTED',
                ],
            ]);

        // Verify account ownership did NOT change
        $account = ConnectedAdAccount::where('external_account_id', 'act_takeover_target_999')->first();
        $this->assertEquals($this->owner->id, $account->user_id);
    }

    public function test_oauth_state_is_bound_to_the_user_who_started_the_flow(): void
    {
        $manager = new \App\Services\Advertising\OAuthManager();
        $state = $manager->generateState('meta', $this->owner->id);
        // A different user cannot consume it, and it is single-use either way.
        $this->assertNull($manager->validateState($state, 'meta', $this->owner->id + 999));
        // The wrong user's attempt must not burn the legitimate user's state.
        $this->assertNotNull($manager->validateState($state, 'meta', $this->owner->id));
        $this->assertNull($manager->validateState($state, 'meta', $this->owner->id));
    }

    public function test_oauth_state_is_bound_to_provider_and_single_use(): void
    {
        $manager = new \App\Services\Advertising\OAuthManager();
        $state = $manager->generateState('meta', $this->owner->id);
        $this->assertNull($manager->validateState($state, 'google', $this->owner->id));

        $state2 = $manager->generateState('meta', $this->owner->id);
        $this->assertNotNull($manager->validateState($state2, 'meta', $this->owner->id));
        $this->assertNull($manager->validateState($state2, 'meta', $this->owner->id));
    }

    public function test_redirect_uri_must_be_on_server_allowlist(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/integrations/oauth/meta/redirect?redirect_uri=https://evil.example/steal')
            ->assertStatus(422)->assertJsonPath('error.code', 'REDIRECT_URI_NOT_ALLOWED');

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/integrations/oauth/meta/callback?code=c&state=s&redirect_uri=https://evil.example/steal')
            ->assertStatus(422)->assertJsonPath('error.code', 'REDIRECT_URI_NOT_ALLOWED');

        config(['oauth.allowed_redirect_uris' => []]);
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/integrations/oauth/meta/redirect?redirect_uri=https://adplatform.local/callback')
            ->assertStatus(422);
    }

    public function test_callback_with_another_users_state_is_rejected_before_any_provider_call(): void
    {
        $manager = new \App\Services\Advertising\OAuthManager();
        $state = $manager->generateState('meta', $this->owner->id + 999);
        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/integrations/oauth/meta/callback?code=c&state=' . $state . '&redirect_uri=https://adplatform.local/callback')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_OAUTH_STATE');
        Http::assertNothingSent();
    }
}
