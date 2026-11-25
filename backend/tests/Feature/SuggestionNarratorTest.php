<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\User;
use App\Services\Advertising\SuggestionNarrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** All provider traffic here is Http::fake. No live provider is ever called. */
class SuggestionNarratorTest extends TestCase
{
    use DatabaseTransactions;

    private const KEY = 'sk-test-REALLOOKING-1234567890';

    private function user(string $e, bool $withAi = true, string $key = self::KEY): User
    {
        $u = User::firstOrCreate(['email' => $e], ['name' => 'U', 'password' => bcrypt('password123'), 'status' => 'active']);
        if ($withAi) {
            AiConfig::create(['user_id' => $u->id, 'provider' => 'openai', 'api_key' => $key, 'model' => 'gpt-4o', 'is_active' => true]);
        }
        return $u;
    }

    private function sug(): array
    {
        return [
            ['kind' => 'budget', 'platform' => 'meta', 'severity' => 'scenario', 'title' => 'Consider raising Meta Ads spend (scenario)', 'text' => 'OR scenario puts Meta Ads at 6000 versus 5000 baseline (marginal ROAS 3.1x now).', 'evidence' => [['source' => 'or_result', 'field' => 'x', 'value' => '6000']], 'mode' => 'suggestion_only'],
            ['kind' => 'data', 'platform' => 'snapchat', 'severity' => 'attention', 'title' => 'Snapchat Ads has no purchase value', 'text' => 'Left out of the OR model.', 'evidence' => [['source' => 'pulled_data', 'field' => 'y', 'value' => 'false']], 'mode' => 'suggestion_only'],
        ];
    }

    private function ai(array $data): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($data)]]], 'usage' => ['total_tokens' => 50]], 200)]);
    }

    private function narrate(User $u, bool $ai = true): array
    {
        return app(SuggestionNarrator::class)->narrate($u, $this->sug(), $ai);
    }

    public function test_not_requested_never_calls_a_provider(): void
    {
        Http::fake();
        $r = $this->narrate($this->user('n1@example.com'), false);
        Http::assertNothingSent();
        $this->assertSame('deterministic', $r['mode']['result']);
        $this->assertArrayNotHasKey('ai_note', $r['suggestions'][0]);
    }

    public function test_valid_order_and_fixed_note_are_applied_and_prompt_has_no_secrets(): void
    {
        $u = $this->user('n2@example.com');
        $this->ai(['priority' => [1, 0], 'notes' => [['index' => 0, 'code' => 'scenario_not_forecast'], ['index' => 1, 'code' => 'data_gap_limits_analysis']]]);
        $r = $this->narrate($u);
        $this->assertSame('ai_ordered', $r['mode']['result']);
        $this->assertSame('order_and_fixed_notes_only', $r['mode']['ai_role']);
        $this->assertSame('Snapchat Ads has no purchase value', $r['suggestions'][0]['title']);
        $this->assertSame(1, $r['suggestions'][0]['ai_rank']);
        $this->assertSame(SuggestionNarrator::NOTES['data_gap_limits_analysis']['text'], $r['suggestions'][0]['ai_note']);
        $orig = $this->sug();
        $this->assertSame($orig[0]['text'], $r['suggestions'][1]['text']);
        $this->assertSame($orig[0]['evidence'], $r['suggestions'][1]['evidence']);
        Http::assertSent(function ($req) use ($u) {
            $b = json_encode($req->data());
            return strpos($b, self::KEY) === false && strpos($b, $u->email) === false && strpos($b, '6000') === false;
        });
    }

    public function test_free_text_from_the_model_never_reaches_the_output(): void
    {
        $u = $this->user('n3@example.com');
        $this->ai(['priority' => [0, 1], 'explanation' => 'Invest everything in LinkedIn', 'notes' => [['index' => 0, 'code' => 'scenario_not_forecast', 'text' => 'Spend six thousand on Snapchat']]]);
        $r = $this->narrate($u);
        $this->assertSame('ai_ordered', $r['mode']['result']);
        $this->assertStringNotContainsString('LinkedIn', json_encode($r));
        $this->assertStringNotContainsString('six thousand', json_encode($r));
    }

    public function test_anything_outside_the_contract_keeps_the_deterministic_order(): void
    {
        $cases = [
            'bad index' => ['priority' => [0, 5], 'notes' => []],
            'repeated index' => ['priority' => [0, 0], 'notes' => []],
            'missing items' => ['priority' => [0], 'notes' => []],
            'non-int index' => ['priority' => ['0', '1'], 'notes' => []],
            'unknown code' => ['priority' => [0, 1], 'notes' => [['index' => 0, 'code' => 'raise_budget_now']]],
            'code on wrong severity' => ['priority' => [0, 1], 'notes' => [['index' => 0, 'code' => 'information_only']]],
            'code on wrong kind' => ['priority' => [0, 1], 'notes' => [['index' => 1, 'code' => 'scenario_not_forecast']]],
            'duplicate note' => ['priority' => [0, 1], 'notes' => [['index' => 0, 'code' => 'scenario_not_forecast'], ['index' => 0, 'code' => 'scenario_not_forecast']]],
            'no priority' => ['notes' => []],
        ];
        $n = 0;
        foreach ($cases as $label => $data) {
            $this->ai($data);
            $r = $this->narrate($this->user('n4_' . ($n++) . '@example.com'));
            $this->assertSame('deterministic_fallback', $r['mode']['result'], $label);
            $this->assertSame('ai_output_outside_contract', $r['mode']['reason'], $label);
            $this->assertSame($this->sug(), $r['suggestions'], $label);
        }
    }

    public function test_no_config_and_placeholder_key_never_send(): void
    {
        Http::fake();
        $this->assertSame('no_ai_config', $this->narrate($this->user('n6@example.com', false))['mode']['reason']);
        $r = $this->narrate($this->user('n7@example.com', true, 'mock_key'));
        $this->assertSame('test_provider_not_used', $r['mode']['reason']);
        Http::assertNothingSent();
    }

    public function test_provider_error_falls_back(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('boom', 500)]);
        $this->assertSame('ai_unavailable', $this->narrate($this->user('n8@example.com'))['mode']['reason']);
    }

    public function test_bad_json_falls_back(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]], 200)]);
        $this->assertSame('ai_output_outside_contract', $this->narrate($this->user('n9@example.com'))['mode']['reason']);
    }

    public function test_other_users_ai_config_is_never_used(): void
    {
        Http::fake();
        $this->user('n10a@example.com');
        $r = $this->narrate($this->user('n10b@example.com', false));
        $this->assertSame('no_ai_config', $r['mode']['reason']);
        Http::assertNothingSent();
    }

    public function test_snapshot_id_is_stable_for_the_same_suggestions_and_changes_with_content(): void
    {
        $n = app(SuggestionNarrator::class);
        $a = $this->sug();
        $b = $this->sug();
        $b[0]['text'] .= ' x';
        $this->assertSame($n->snapshotId($a), $n->snapshotId($this->sug()));
        $this->assertNotSame($n->snapshotId($a), $n->snapshotId($b));
    }

    public function test_endpoint_uses_the_or_window_for_coverage_and_returns_mode_and_one_snapshot(): void
    {
        $u = $this->user('n11@example.com', false);
        $meta = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => 'm', 'account_name' => 'M', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
        $g = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'google', 'external_account_id' => 'g', 'account_name' => 'G', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 't', 'status' => 'active']);
        $metaDay = Carbon::now()->subDays(3)->format('Y-m-d');
        $mk = fn($a, $p, $d) => PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$a->provider,'count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id' => $a->id, 'provider' => $p, 'date' => $d, 'spend' => 10, 'impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 30, 'currency' => 'USD']);
        $mk($meta, 'meta', $metaDay);
        $mk($g, 'google', Carbon::now()->subDay()->format('Y-m-d')); // newer than the common OR window end
        $r = $this->actingAs($u, 'sanctum')->getJson('/api/v1/analytics/suggestions');
        $r->assertOk();
        $this->assertSame('or_window', $r->json('data.grounding.window_source'));
        $this->assertSame($metaDay, $r->json('data.grounding.window.1'));
        $this->assertSame($r->json('data.mode.snapshot_id'), $r->json('data.grounding.snapshot_id'));
        $this->assertSame('ai_not_requested', $r->json('data.mode.reason'));
        $this->assertCount(6,$r->json('data.analyst_evidence'));
        $this->assertSame($r->json('data.mode.snapshot_id'),$r->json('data.analyst_evidence.actual_metrics.snapshot_id'));
        // Google's newer day is outside the shared window, exactly as for the OR engine.
        $titles = implode('|', array_column($r->json('data.suggestions'), 'title'));
        $this->assertStringContainsString('No report rows pulled for Google Ads', $titles);
    }
    public function test_provider_receives_six_block_evidence_but_cannot_replace_results():void{
        $this->ai(['priority'=>[0,1],'notes'=>[]]);$e=(new \App\Services\Advertising\AnalystEvidence)->build(['status'=>'ok','total_budget'=>731,'optimal_total_revenue'=>932],'one');$r=app(SuggestionNarrator::class)->narrate($this->user('six-block@example.com'),$this->sug(),true,$e);$this->assertSame('ai_ordered',$r['mode']['result']);Http::assertSent(function($req){$data=$req->data();$prompt=$data['messages'][1]['content']??'';return str_contains($prompt,'actual_metrics')&&str_contains($prompt,'forecast')&&str_contains($prompt,'optimization')&&str_contains($prompt,'diagnostics')&&str_contains($prompt,'confidence')&&str_contains($prompt,'data_quality')&&str_contains($prompt,'932');});
    }
}
