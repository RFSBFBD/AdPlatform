<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** OR result, rule suggestions and the optional AI step all hang on one server snapshot_id. Http::fake only. */
class SnapshotConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    private function seed1(string $email): array
    {
        $u = User::firstOrCreate(['email' => $email], ['name' => 'U', 'password' => bcrypt('password123'), 'status' => 'active']);
        AiConfig::create(['user_id' => $u->id, 'provider' => 'openai', 'api_key' => 'sk-test-REALLOOKING-1234567890', 'model' => 'gpt-4o', 'is_active' => true]);
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => 'm' . uniqid(), 'account_name' => 'M', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
        $d = Carbon::now()->subDays(2)->format('Y-m-d');
        PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => 'meta', 'date' => $d, 'spend' => 10, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 30, 'currency' => 'USD']);
        return [$u, $a, $d];
    }

    private function fakeOrder(int $n): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['priority' => range(0, $n - 1), 'notes' => []])]]], 'usage' => ['total_tokens' => 10]], 200)]);
    }

    public function test_or_response_carries_suggestions_and_the_same_snapshot_id_as_the_suggestions_endpoint(): void
    {
        [$u] = $this->seed1('snap1@example.com');
        $or = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/operations-research');
        $or->assertOk();
        $this->assertIsArray($or->json('suggestions'));
        $this->assertNotEmpty($or->json('snapshot_id'));
        $sg = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions');
        $this->assertSame($or->json('snapshot_id'), $sg->json('data.grounding.snapshot_id'));
        $this->assertSame($or->json('suggestions'), $sg->json('data.suggestions'));
    }

    public function test_ai_requires_the_matching_snapshot_and_sends_nothing_otherwise(): void
    {
        [$u] = $this->seed1('snap2@example.com');
        Http::fake();
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions?ai=1')->assertStatus(409)->assertJsonPath('error.code', 'SNAPSHOT_CHANGED');
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions?ai=1&snapshot_id=deadbeef')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_matching_snapshot_lets_the_ai_step_run(): void
    {
        [$u] = $this->seed1('snap3@example.com');
        $id = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/operations-research')->json('snapshot_id');
        $this->fakeOrder(count($this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions')->json('data.suggestions')));
        $r = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions?ai=1&snapshot_id=' . $id);
        $r->assertOk();
        $this->assertSame($id, $r->json('data.grounding.snapshot_id'));
        $this->assertSame('ai_ordered', $r->json('data.mode.result'));
    }

    public function test_a_sync_arriving_between_the_two_requests_stops_the_ai_step(): void
    {
        [$u, $a] = $this->seed1('snap4@example.com');
        $id = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/operations-research')->json('snapshot_id');
        PerformanceMetric::create(['connected_ad_account_id' => $a->id, 'provider' => 'meta', 'date' => Carbon::now()->subDays(3)->format('Y-m-d'), 'spend' => 99, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 1, 'currency' => 'USD']);
        Http::fake();
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions?ai=1&snapshot_id=' . $id)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_different_or_inputs_give_a_different_snapshot(): void
    {
        [$u] = $this->seed1('snap5@example.com');
        $a = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/operations-research')->json('snapshot_id');
        $b = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/operations-research?budget=500&budget_horizon=per_30_days')->json('snapshot_id');
        $this->assertNotSame($a, $b);
    }
}
