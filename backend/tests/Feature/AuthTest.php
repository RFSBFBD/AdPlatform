<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions for tests
        (new RoleAndPermissionSeeder())->run(); // direct run: avoids artisan in php-wasm
        // The seeder has no built-in password; tests set their own throwaway one.
        \App\Models\User::where('email', 'admin@adplatform.local')
            ->update(['password' => \Illuminate\Support\Facades\Hash::make(self::TEST_PASSWORD)]);
    }

    private const TEST_PASSWORD = 'Test-Only-Password-1!';

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@adplatform.local',
            'password' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'user' => ['id', 'email', 'name', 'roles'],
                         'token',
                         'token_type',
                     ],
                 ]);

        $this->assertTrue($response->json('success'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@adplatform.local',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401)
                 ->assertJson([
                     'success' => false,
                     'error' => [
                         'code' => 'INVALID_CREDENTIALS',
                     ],
                 ]);
    }

    public function test_unauthenticated_request_to_me_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::where('email', 'admin@adplatform.local')->first();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'email' => 'admin@adplatform.local',
                     ],
                 ]);
    }

    public function test_viewer_cannot_access_user_management(): void
    {
        $viewer = User::create([
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => Hash::make('ViewerPass123!'),
            'status' => 'active',
        ]);
        $viewer->assignRole('Viewer');

        $token = $viewer->createToken('viewer_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->getJson('/api/v1/users');

        $response->assertStatus(403);
    }

    public function test_owner_can_directly_create_user_with_password(): void
    {
        $owner = User::where('email', 'admin@adplatform.local')->first();
        $token = $owner->createToken('owner_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
                         ->postJson('/api/v1/users', [
                             'name' => 'Direct Employee',
                             'email' => 'employee@company.local',
                             'password' => 'EmployeePass2026!',
                             'role' => 'Analyst',
                         ]);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'name' => 'Direct Employee',
                         'email' => 'employee@company.local',
                     ],
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'employee@company.local',
            'name' => 'Direct Employee',
        ]);
    }
}
