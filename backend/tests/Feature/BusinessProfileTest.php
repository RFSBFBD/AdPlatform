<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);
        $u = User::firstOrCreate(['email' => $email], ['name' => 'T', 'password' => bcrypt('password123'), 'status' => 'active']);
        if (! $u->hasRole('Admin')) {
            $u->assignRole('Admin'); // business-profile writes now need manage-accounts
        }
        return $u;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/business-profile')->assertStatus(401);
    }

    public function test_empty_profile_lists_missing_fields_and_invents_nothing(): void
    {
        $u = $this->user('bp_empty@example.com');
        BusinessProfile::where('user_id', $u->id)->delete();
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/business-profile')
            ->assertStatus(200)
            ->assertJsonPath('data', null)
            ->assertJsonPath('missing_fields', ['business_model', 'currency', 'primary_goal', 'conversion_definition']);
    }

    public function test_update_saves_validated_fields_for_the_authenticated_user_only(): void
    {
        $a = $this->user('bp_a@example.com');
        $b = $this->user('bp_b@example.com');
        BusinessProfile::whereIn('user_id', [$a->id, $b->id])->delete();

        $this->actingAs($a, 'sanctum')->putJson('/api/v1/business-profile', [
            'user_id' => $b->id,
            'business_model' => 'ecommerce', 'currency' => 'usd', 'primary_goal' => 'roas',
            'conversion_definition' => 'Purchase', 'gross_margin_percent' => 35,
        ])->assertStatus(200)->assertJsonPath('data.currency', 'USD')->assertJsonPath('missing_fields', []);

        $this->assertNotNull(BusinessProfile::where('user_id', $a->id)->first());
        $this->assertNull(BusinessProfile::where('user_id', $b->id)->first());

        $this->actingAs($b, 'sanctum')->getJson('/api/v1/business-profile')->assertJsonPath('data', null);
    }

    public function test_rejects_invalid_values(): void
    {
        $u = $this->user('bp_bad@example.com');
        $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', [
            'business_model' => 'nope', 'gross_margin_percent' => 150, 'currency' => 'US',
        ])->assertStatus(422);
    }

    public function test_cap_requires_horizon(): void
    {
        $u = $this->user('bp_cap@example.com');
        $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', ['budget_cap' => 1000])->assertStatus(422);
        $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', ['budget_cap' => 1000, 'budget_cap_horizon' => 'weekly'])->assertStatus(422);
        $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', ['budget_cap' => 1000, 'budget_cap_horizon' => 'per_30_days'])->assertStatus(200);
    }

    public function test_custom_fields_reject_secrets_bad_keys_and_oversize(): void
    {
        $u = $this->user('bp_cf@example.com');
        $put = fn(array $cf) => $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', ['custom_fields' => $cf]);
        $put(['api_key' => 'abc'])->assertStatus(422);
        $put(['Bad Key' => 'x'])->assertStatus(422);
        $put(['note' => '4111 1111 1111 1111'])->assertStatus(422);
        $put(['note' => str_repeat('a', 201)])->assertStatus(422);
        $put(['season' => 'Ramadan peak'])->assertStatus(200)->assertJsonPath('data.custom_fields.season', 'Ramadan peak');
    }

    public function test_numeric_fields_respect_storage_limits(): void
    {
        $u = $this->user('bp_big@example.com');
        $put = fn(array $b) => $this->actingAs($u, 'sanctum')->putJson('/api/v1/business-profile', $b);
        $put(['average_order_value' => 1e15])->assertStatus(422);
        $put(['target_roas' => 1e7])->assertStatus(422);
        $put(['budget_cap' => 1e15, 'budget_cap_horizon' => 'daily'])->assertStatus(422);
        $put(['custom_fields' => [str_repeat('a', 41) => 'x']])->assertStatus(422);
        $put(['custom_fields' => array_fill_keys(array_map(fn($i) => 'k' . $i, range(1, 21)), 'v')])->assertStatus(422);
        $put(['custom_fields' => ['note' => ['nested']]])->assertStatus(422);
    }
}
