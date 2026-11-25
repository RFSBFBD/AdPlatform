<?php

namespace Tests\Feature;

use App\Models\ConnectedAdAccount;
use App\Models\ProviderDeveloperConfig;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Advertising\Adapters\GoogleAdsAdapter;
use App\Services\Advertising\OAuthManager;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use App\Services\Advertising\Sync\GoogleNativeRecordedMetrics;
use App\Services\Advertising\Sync\RecordedDeliveryRepository;
use App\Services\Analytics\RecordedDeliveryKpis;
use App\Services\Analytics\RecordedDeliverySeries;
use App\Services\Decision\Accounting\RecordedJson;
use App\Services\OperationsResearch\OptimizationOrchestrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class OriginalReportingCaptureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_google_existing_sync_captures_original_tokens_without_claiming_exact_import(): void
    {
        $account = ConnectedAdAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'google', 'external_account_id' => '1234567890',
            'account_name' => 'Synthetic', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'access_token' => 'fixture',
            'metadata' => ['metadata_source' => ['version' => 'provider-discovery-explicit-1', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo']]]);
        $config = new ProviderDeveloperConfig(['provider' => 'google', 'app_id' => 'fixture', 'app_secret' => 'fixture', 'developer_token' => 'fixture', 'api_version' => 'v26']);
        $original = '{"results":[{"campaign":{"id":"111"},"segments":{"date":"2026-11-01"},"metrics":{"costMicros":"1000001","impressions":"100","clicks":"2","conversions":1.000000123456789,"conversionsValue":123.123456789012345}}]}';
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($original) {
            $query = $request['query'];
            self::assertArrayNotHasKey('pageSize', $request->data());
            if (str_contains($query, 'FROM ad_group_ad')) {
                return Http::response(['results' => []]);
            }
            if (str_contains($query, 'metrics.cost_micros')) {
                return Http::response($original, 200, ['Content-Type' => 'application/json', 'request-id' => 'fixture-request']);
            }

            return Http::response(['results' => [['campaign' => ['id' => '111', 'name' => 'Fixture', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH']]]]);
        });
        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('getAdapter')->andReturn(new GoogleAdsAdapter($config));
        $run = (new AdDataSyncEngine($manager))->syncAccount($account, 'manual', '2026-11-01', '2026-11-14');
        self::assertSame('completed', $run->status, $run->error_message ?? '');
        $row = DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->first();
        self::assertNotNull($row);
        self::assertSame($original, $row->body);
        self::assertSame(hash('sha256', $original), $row->body_sha256);
        self::assertSame('unqualified_original_reporting_bytes', $row->admission_state);
        $native = json_decode($row->native_metrics_json, true);
        self::assertSame('native_recorded', $native['status']);
        self::assertSame('current_documented_contract_not_live_verified', $native['api_lifecycle']);
        self::assertSame('1.000000123456789', $native['rows'][3]['native_value']);
        self::assertSame('google_cost_micros', $native['rows'][0]['unit']);
        self::assertSame('1.000001', $native['rows'][0]['exact_account_currency_candidate']);
        self::assertSame('native_recorded_not_canonical_qualified', $native['rows'][3]['admission_state']);
        $parsed = RecordedJson::decode($row->body);
        self::assertSame('1.000000123456789', $parsed['results'][0]['metrics']['conversions']);
        self::assertSame('123.123456789012345', $parsed['results'][0]['metrics']['conversionsValue']);
        self::assertSame('v26', json_decode($row->provenance, true)['request']['api_version']);
        $snapshot = (new RecordedDeliveryRepository)->latestForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-14');
        self::assertSame('1.000001', $snapshot['totals']['spend']);
        self::assertNull($snapshot['totals']['conversions']);
        self::assertSame('recorded_source', $snapshot['scope']['provenance_kind']);
        self::assertSame('1.000000123456789', $snapshot['scope']['native_extensions']['metrics'][0]['native_value']);
        self::assertSame('123.123456789012345', $snapshot['scope']['native_extensions']['metrics'][1]['native_value']);
        self::assertSame('unavailable_no_equivalence_selected', $snapshot['scope']['native_extensions']['metrics'][1]['canonical_mapping']);
        self::assertSame('fixture-request', $snapshot['scope']['source_lineage']['pages'][0]['request_id']);
        self::assertSame($row->body_sha256, $snapshot['scope']['source_lineage']['pages'][0]['body_sha256']);
        self::assertSame(json_decode($row->provenance, true)['retrieved_at'], $snapshot['scope']['source_lineage']['pages'][0]['retrieved_at']);
        $response = $this->actingAs($account->user, 'sanctum')->getJson('/api/v1/analytics/kpis?provider=google&start_date=2026-11-01&end_date=2026-11-14')->assertOk();
        self::assertSame('1.000001', $response->json('data.groups.google/EGP.recorded_arithmetic.metrics.spend.decimal'));
        self::assertSame('exact_over_original_reported_delivery_operands', $response->json('data.groups.google/EGP.recorded_arithmetic.arithmetic'));
        self::assertNull($response->json('data.groups.google/EGP.recorded_arithmetic.metrics.conversions.decimal'));
        $cov = $this->getJson('/api/v1/analytics/data-coverage?start_date=2026-11-01&end_date=2026-11-14')->assertOk();
        $google = collect($cov->json('data.platforms'))->firstWhere('provider', 'google');
        self::assertSame('1.000001', $google['recorded_arithmetic_by_currency']['EGP']['metrics']['spend']['decimal']);
        self::assertNull($google['recorded_arithmetic_by_currency']['EGP']['metrics']['conversion_value']['decimal']);
        $overview = $this->getJson('/api/v1/analytics/overview?provider=google&start_date=2026-11-01&end_date=2026-11-14')->assertOk();
        self::assertSame('1.000001', $overview->json('recorded_native_evidence.groups.google/EGP.recorded_arithmetic.metrics.spend.decimal'));
        self::assertFalse($overview->json('recorded_native_evidence.foreign_exchange_applied'));
        $series = (new RecordedDeliverySeries)->forWindow((int) $account->user_id, 'google', '2026-11-01', '2026-11-14');
        self::assertSame('recorded_delivery_rows_only', $series['status']);
        self::assertSame('1.000001', $series['points'][0]['recorded_arithmetic']['metrics']['spend']['decimal']);
        self::assertNull($series['points'][0]['recorded_arithmetic']['metrics']['conversions']['decimal']);
        self::assertSame('1.000001', $series['points'][0]['recorded_arithmetic']['metrics']['spend']['decimal']);
        self::assertFalse($series['optimization_admission']);
        self::assertSame('not_drawn_no_complete_population_claim', $series['chart_status']);
        self::assertSame($snapshot['snapshot_id'], $series['source_evidence'][0]['snapshot_id']);
        $evidence = $series['source_evidence'][0];
        self::assertSame('1.000001', $evidence['analyst_input']['actual_metrics']['totals']['spend']);
        $trace = $evidence['contribution_trace'];
        self::assertSame('traced_supplied_contributions', $trace['status']);
        self::assertSame('1.000001', $trace['recomputed_totals']['spend']);
        self::assertTrue($trace['matches_recorded_totals']['spend']);
        self::assertStringStartsWith($row->body_sha256.':/results/0', $trace['rows'][0]['source_ref']);
        self::assertSame('fixture-request', $trace['source_lineage']['pages'][0]['request_id']);
        self::assertSame('123.123456789012345', $trace['native_extensions']['metrics'][1]['native_value']);
        self::assertStringNotContainsString('123.123456789012345', json_encode($evidence['analyst_input']));

        self::assertFalse($evidence['analyst_input']['data_quality']['source_population_qualified']);
        self::assertSame('source_only_abstained', $evidence['analyst_input']['optimization']['status']);
        self::assertSame('unavailable', $evidence['charts']['performance_trend']['status']);
        self::assertSame('source_population_not_qualified', $evidence['diagnostics']['campaign:111']['reason']);
        Carbon::setTestNow('2026-11-15T12:00:00+02:00');
        try {
            $this->actingAs($account->user, 'sanctum')->getJson('/api/v1/analytics/observed-series?provider=google&days=14')->assertOk()->assertJsonPath('data.status', 'recorded_delivery_rows_only')->assertJsonPath('data.points.0.recorded_arithmetic.metrics.spend.decimal', '1.000001');
            $this->getJson('/api/v1/analytics/forecast?provider=google&metric=spend&days=14')->assertOk()->assertJsonPath('data.status', 'insufficient_data')->assertJsonPath('data.source_snapshot_ids.0', $snapshot['snapshot_id'])->assertJsonPath('data.calibration', 'not_established')->assertJsonPath('data.points', []);
        } finally {
            Carbon::setTestNow();
        }
        $spec = array_intersect_key($snapshot['scope'], array_flip(['currency', 'outcome_definition', 'reporting_window', 'timezone', 'population', 'outcome_kind'])) + [
            'source_snapshot_id' => $snapshot['snapshot_id'], 'objective' => 'MAXIMIZE_CONVERSIONS', 'response_metric' => 'conversions', 'response_unit' => 'unavailable',
            'business_estimand' => 'no_canonical_outcome_selected', 'budget_semantics' => 'period_total_not_daily_rate', 'horizon_days' => 14,
            'forecast_horizon_days' => 1, 'budget_cap' => '10', 'minimum_spend' => '0', 'minimum_response' => '0', 'allocation_mode' => 'finite_menu', 'interaction_model' => 'separable_no_overlap_claim', 'groups' => [],
            'variables' => [['id' => 'campaign:111', 'platform' => 'google', 'campaign_type' => 'SEARCH', 'entity_level' => 'campaign', 'entity_id' => '111', 'current_spend' => '1.000001', 'minimum' => '0', 'maximum' => '10', 'menu' => ['0', '10']]]];
        $request = ['account_id' => $account->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-14', 'problem' => $spec];
        $preview = $this->postJson('/api/v1/analytics/decision-evidence', $request)->assertOk();
        $preview->assertJsonPath('data.evidence.status', 'abstained')->assertJsonPath('data.evidence.scenarios', [])->assertJsonPath('data.evidence.operational_approval', false);
        self::assertContains('recorded_source_bridge_not_yet_qualified', $preview->json('data.evidence.route.reasons'));
        self::assertSame('recorded_source_population_not_qualified_for_forecast', $preview->json('data.evidence.forecast.reason'));
        self::assertSame('1.000001', $preview->json('data.evidence.actual.totals.spend'));
        self::assertSame('fixture-request', $preview->json('data.evidence.actual.scope.source_lineage.pages.0.request_id'));
        self::assertSame($row->body_sha256, $preview->json('data.evidence.actual.scope.source_lineage.pages.0.body_sha256'));
        self::assertStringNotContainsString('fixture-request', json_encode($preview->json('data.evidence.consumers.analyst_input')));

        $id = $preview->json('data.id');
        $this->getJson('/api/v1/analytics/decision-evidence/'.$id.'/question?intent=actual')->assertOk()->assertJsonPath('data.sections.0.value.spend', '1.000001')->assertJsonPath('data.external_ai_called', false);
        $this->getJson('/api/v1/analytics/decision-evidence/'.$id.'/question?intent=invent')->assertOk()->assertJsonPath('data.status', 'unsupported_question');
        // Evidence-constrained assistance: no call unless asked; Http::fake only, never a live provider.
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => json_encode(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']]])]]], 'usage' => ['total_tokens' => 30]], 200)]);
        $assist = '/api/v1/analytics/decision-evidence/'.$id.'/assist';
        $this->postJson($assist, ['question' => 'كم الإنفاق؟', 'use_ai' => false])->assertOk()->assertJsonPath('data.status', 'ai_not_requested')->assertJsonPath('data.external_ai_called', false);
        $this->postJson($assist, ['question' => 'كم الإنفاق؟', 'use_ai' => true])->assertOk()->assertJsonPath('data.status', 'no_ai_config');
        \Illuminate\Support\Facades\Http::assertNothingSent();
        \App\Models\AiConfig::create(['user_id' => $account->user->id, 'provider' => 'openai', 'api_key' => 'sk-test-REALLOOKING-1234567890', 'model' => 'gpt-4o', 'is_active' => true]);
        $assisted = $this->postJson($assist, ['question' => 'كم الإنفاق؟', 'use_ai' => true]);
        $assisted->assertOk()->assertJsonPath('data.status', 'evidence_constrained_answer')->assertJsonPath('data.claims.0.text', 'actual / totals / spend: 1.000001 [ACTUAL]')->assertJsonPath('data.external_ai_called', true);
        $real = $assisted->json('data');
        self::assertSame($snapshot['snapshot_id'], $real['snapshot_id']);
        $real['snapshot_id'] = 'SNAPSHOT';
        $realJson = json_encode(['success' => true, 'data' => $real], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $fixture = __DIR__.'/../fixtures/assist-route-response.json';
        if (getenv('UPDATE_FIXTURES') === '1') {
            file_put_contents($fixture, $realJson);
            file_put_contents(dirname(__DIR__, 3).'/frontend/src/app/features/analytics-lab/testdata/assist-route-response.json', $realJson);
        }
        self::assertSame($realJson, file_get_contents($fixture));
        self::assertSame($realJson, file_get_contents(dirname(__DIR__, 3).'/frontend/src/app/features/analytics-lab/testdata/assist-route-response.json'));

        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => json_encode(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend', 'text' => 'ارفع الميزانية']]])]]]], 200)]);
        $this->postJson($assist, ['question' => 'x', 'use_ai' => true])->assertOk()->assertJsonPath('data.status', 'rejected_outside_evidence');
        $this->postJson($assist, ['question' => 'x'])->assertStatus(422);
        $this->getJson('/api/v1/analytics/decision-evidence?limit=1')->assertOk()->assertJsonPath('data.rows.0.id', $id);

        $this->getJson('/api/v1/analytics/decision-evidence?limit=21')->assertStatus(422);
        $second = $this->postJson('/api/v1/analytics/decision-evidence', $request)->assertOk()->json('data.id');
        $this->getJson('/api/v1/analytics/decision-evidence?limit=1')->assertOk()->assertJsonPath('data.rows.0.id', $second)->assertJsonPath('data.next_before_id', $second);
        $this->getJson('/api/v1/analytics/decision-evidence?limit=1&before_id='.$second)->assertOk()->assertJsonPath('data.rows.0.id', $id)->assertJsonPath('data.next_before_id', null);
        $saved = DB::table('decision_evidence_runs')->where('id', $second)->value('evidence_json');
        DB::table('decision_evidence_runs')->where('id', $second)->update(['evidence_json' => '{}']);
        $this->getJson('/api/v1/analytics/decision-evidence/'.$second)->assertStatus(409)->assertJsonPath('error', 'owned_evidence_integrity_check_failed');
        $this->postJson('/api/v1/analytics/decision-evidence/'.$second.'/replay')->assertStatus(409);
        $this->getJson('/api/v1/analytics/decision-evidence/'.$second.'/question?intent=actual')->assertStatus(409);
        $this->postJson('/api/v1/analytics/decision-evidence/'.$second.'/assist', ['question' => 'x', 'use_ai' => true])->assertStatus(409)->assertJsonPath('error', 'owned_evidence_integrity_check_failed');
        DB::table('decision_evidence_runs')->where('id', $second)->update(['evidence_json' => $saved]);
        $this->getJson('/api/v1/analytics/decision-evidence/'.$id)->assertOk()->assertJsonPath('data.evidence.snapshot_id', $snapshot['snapshot_id']);
        $this->postJson('/api/v1/analytics/decision-evidence/'.$id.'/replay')->assertOk()->assertJsonPath('data.matches_scenarios', true)->assertJsonPath('data.matches_consumers', true);
        $this->postJson('/api/v1/analytics/decision-evidence', $request + ['models' => []])->assertStatus(422);
        $bad = $request;
        $bad['problem']['source_snapshot_id'] = 'forged';
        $this->postJson('/api/v1/analytics/decision-evidence', $bad)->assertStatus(422);
        $bad = $request;
        $bad['problem']['variables'][0]['entity_id'] = '222';
        $this->postJson('/api/v1/analytics/decision-evidence', $bad)->assertStatus(422);
        $bad = $request;
        $bad['problem']['groups'] = [['id' => 'malformed', 'minimum' => '0', 'maximum' => '10', 'members' => [['nested']]]];
        $this->postJson('/api/v1/analytics/decision-evidence', $bad)->assertStatus(422)->assertJsonPath('error', 'invalid_atomic_group_constraint');
        $uncaptured = ConnectedAdAccount::create(['user_id' => $account->user_id, 'provider' => 'google', 'external_account_id' => '9999999999', 'account_name' => 'Synthetic uncaptured', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'access_token' => 'fixture']);
        $mixed = (new RecordedDeliveryKpis)->forGroup((int) $account->user_id, 'google', 'EGP', '2026-11-01', '2026-11-14');
        self::assertNull($mixed['metrics']['spend']['decimal']);
        self::assertSame('complete_selected_account_original_sources_required', $mixed['reason']);
        self::assertSame('not_computable', (new RecordedDeliverySeries)->forWindow((int) $account->user_id, 'google', '2026-11-01', '2026-11-14')['status']);
        self::assertSame('legacy_only_no_original_capture', (new RecordedDeliveryRepository)->selectForAccount($uncaptured, (int) $account->user_id, '2026-11-01', '2026-11-14')['status']);
        $uncaptured->delete();
        foreach (['/api/v1/analytics/anomalies?provider=google&metric=spend', '/api/v1/analytics/diagnostics?provider=google', '/api/v1/analytics/campaign-health?provider=google'] as $path) {
            $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'insufficient_data');
        }
        $this->postJson('/api/v1/analytics/entity-optimization', ['provider' => 'google', 'entity_level' => 'campaign', 'objective' => 'MAXIMIZE_CONVERSIONS', 'budget' => 10])->assertOk()->assertJsonPath('data.status', 'unqualified_original_source')->assertJsonPath('data.allocations', []);
        $portfolio = app(OptimizationOrchestrator::class)->run((int) $account->user_id, ['budget' => 10, 'curve_mode' => 'assumed']);
        self::assertSame('unqualified_original_source', $portfolio['status']);
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/analytics/decision-evidence')->assertOk()->assertJsonPath('data.rows', []);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/analytics/decision-evidence/'.$id)->assertNotFound();
        $this->postJson('/api/v1/analytics/decision-evidence/'.$id.'/replay')->assertNotFound();
        $this->getJson('/api/v1/analytics/decision-evidence/'.$id.'/question?intent=actual')->assertNotFound();
        $this->postJson('/api/v1/analytics/decision-evidence/'.$id.'/assist', ['question' => 'x', 'use_ai' => true])->assertNotFound();
        $this->postJson('/api/v1/analytics/decision-evidence', $request)->assertNotFound();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/analytics/kpis?provider=google&start_date=2026-11-01&end_date=2026-11-14')->assertOk()->assertJsonPath('data.groups', []);
        self::assertNull((new RecordedDeliveryRepository)->latestForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-02'));
        $newer = SyncRun::create(['connected_ad_account_id' => $account->id, 'sync_type' => 'manual', 'status' => 'failed', 'started_at' => now()]);
        self::assertNull((new RecordedDeliveryRepository)->latestForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-14'));
        self::assertSame('unavailable_original', (new RecordedDeliveryRepository)->selectForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-14')['status']);
        $this->actingAs($account->user, 'sanctum')->getJson('/api/v1/analytics/kpis?provider=google&start_date=2026-11-01&end_date=2026-11-14')->assertOk()->assertJsonPath('data.groups.google/EGP.recorded_arithmetic.metrics.spend.decimal', null)->assertJsonPath('data.groups.google/EGP.recorded_arithmetic.reason', 'current_original_window_missing_failed_or_superseded_no_legacy_substitution');
        self::assertSame('not_computable', (new RecordedDeliverySeries)->forWindow((int) $account->user_id, 'google', '2026-11-01', '2026-11-14')['status']);
        $this->getJson('/api/v1/analytics/kpis?provider=google&start_date=2026-11-01&end_date=2026-11-02')->assertOk()->assertJsonPath('data.groups.google/EGP.recorded_arithmetic.metrics.spend.decimal', null);
        $newer->delete();
        DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->update(['body' => $original.' ']);
        try {
            (new RecordedDeliveryRepository)->latestForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-14');
            self::fail('modified source bytes accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('recorded_delivery_original_page_integrity_mismatch', $e->getMessage());
        }
        $this->actingAs($account->user, 'sanctum')->getJson('/api/v1/analytics/kpis?provider=google&start_date=2026-11-01&end_date=2026-11-14')->assertOk()->assertJsonPath('data.groups.google/EGP.recorded_arithmetic.arithmetic', 'unavailable_original_source_integrity')->assertJsonPath('data.groups.google/EGP.recorded_arithmetic.metrics.spend.decimal', null);
        $broken = (new RecordedDeliverySeries)->forWindow((int) $account->user_id, 'google', '2026-11-01', '2026-11-14');
        self::assertSame('not_computable', $broken['status']);
        DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->update(['body' => $original]);
        $this->actingAs($account->user, 'sanctum')->postJson('/api/v1/analytics/decision-evidence/'.$id.'/replay')->assertOk()->assertJsonPath('data.matches_consumers', true);

        DB::table('recorded_delivery_snapshots')->where('sync_run_id', $run->id)->update(['body' => '{}']);
        $this->expectException(\InvalidArgumentException::class);
        (new RecordedDeliveryRepository)->latestForAccount($account, (int) $account->user_id, '2026-11-01', '2026-11-14');
    }

    public function test_native_mapper_preserves_missing_and_rejects_version_or_query_drift(): void
    {
        $mapper = new GoogleNativeRecordedMetrics;
        $p = ['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'request' => ['api_version' => 'v21',
            'query' => "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '2026-11-01' AND '2026-11-01'"]];
        $r = $mapper->extract('{"results":[{"campaign":{"id":"1"},"segments":{"date":"2026-11-01"},"metrics":{"costMicros":"1234567","impressions":"0","clicks":"0"}}]}', $p);
        self::assertSame('1234567', $r['rows'][0]['native_value']);
        self::assertNull($r['rows'][3]['native_value']);
        self::assertSame('omitted_unknown_not_zero', $r['rows'][3]['availability']);
        $p['request']['api_version'] = 'v26';
        self::assertSame('current_documented_contract_not_live_verified', $mapper->extract('{"results":[]}', $p)['api_lifecycle']);
        $p['request']['api_version'] = 'v999';
        self::assertSame('unsupported_version', $mapper->extract('{"results":[]}', $p)['status']);
        $p['request']['api_version'] = 'v21';
        $p['request']['query'] .= ' AND metrics.clicks > 0';
        self::assertSame('unsupported_query_shape', $mapper->extract('{"results":[]}', $p)['status']);
    }
}
