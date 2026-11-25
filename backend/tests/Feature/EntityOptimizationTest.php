<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\OptimizationRun;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EntityOptimizationTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $u = User::factory()->create();
        $a = ConnectedAdAccount::create(['user_id' => $u->id, 'provider' => 'meta', 'external_account_id' => uniqid(), 'account_name' => 'Fixture', 'currency' => 'USD', 'timezone' => 'UTC', 'access_token' => 'fixture']);
        $c = Campaign::create(['connected_ad_account_id' => $a->id, 'provider' => 'meta', 'external_campaign_id' => uniqid(), 'name' => 'Fixture', 'currency' => 'USD', 'status' => 'ACTIVE']);
        for ($i = 30; $i >= 1; $i--) {
            $sp = 50 + (30 - $i) * 5;
            PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id' => $a->id, 'campaign_id' => $c->id, 'provider' => 'meta', 'currency' => 'USD', 'date' => now()->subDays($i)->toDateString(), 'spend' => $sp, 'impressions' => 1000, 'clicks' => 100, 'conversions' => 100 * (1 - exp(-.01 * $sp)), 'conversion_value' => 1000 * (1 - exp(-.01 * $sp))]);
        }

        return [$u, $a, $c];
    }

    public function test_explicit_conversion_objective_uses_conversion_curve_not_revenue(): void
    {
        [$u,$a,$c] = $this->fixture();
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'objective' => 'MAXIMIZE_CONVERSIONS', 'budget' => 3000, 'provider' => 'meta']);
        $r->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.response_metric', 'conversions')->assertJsonPath('data.sensitivity.status','ok')->assertJsonPath('data.stress.status','ok');
        $this->assertLessThan(4000, $r->json('data.scenario_response'));
        $this->assertCount(1, $r->json('data.allocations'));
        $this->assertDatabaseCount('campaigns', Campaign::count());
    }

    public function test_missing_entity_days_stop_allocation_not_zero_fill(): void
    {
        [$u,$a,$c] = $this->fixture();
        PerformanceMetric::where('campaign_id', $c->id)->whereDate('date', now()->subDay())->delete();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'objective' => 'MAXIMIZE_REVENUE', 'budget' => 3000, 'provider' => 'meta'])->assertOk()->assertJsonPath('data.status', 'incomplete_entity_history');
    }

    public function test_other_users_entities_not_in_pool_and_level_not_fabricated(): void
    {
        [$u,$a,$c] = $this->fixture();
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'objective' => 'MAXIMIZE_REVENUE', 'budget' => 3000])->assertJsonPath('data.status', 'insufficient_data');
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'ad', 'objective' => 'MAXIMIZE_REVENUE', 'budget' => 3000])->assertJsonPath('data.status', 'insufficient_data');
    }

    public function test_selected_level_ignores_account_parent_without_double_count(): void
    {
        [$u,$a,$c] = $this->fixture();
        PerformanceMetric::create(['metadata'=>['outcome_contract'=>['version'=>'source-outcomes-1','provider'=>'meta','count_metric'=>'synthetic-purchase','value_metric'=>'synthetic-purchase-value','attribution_basis'=>'synthetic-only','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false],'normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase']],'connected_ad_account_id' => $a->id, 'provider' => 'meta', 'currency' => 'USD', 'date' => now()->subDay()->toDateString(), 'spend' => 99999, 'impressions' => 100000, 'clicks' => 10000, 'conversions' => 99999, 'conversion_value' => 999999]);
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'objective' => 'MAXIMIZE_REVENUE', 'budget' => 3000]);
        $r->assertJsonPath('data.status', 'ok');
        $this->assertLessThan(30000, $r->json('data.actual_response'));
    }

    public function test_metric_cannot_reference_another_accounts_campaign(): void
    {
        [$u,$a,$c] = $this->fixture();
        [$other,$b,$foreign] = $this->fixture();
        PerformanceMetric::where('connected_ad_account_id', $a->id)->update(['campaign_id' => $foreign->id]);
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'objective' => 'MAXIMIZE_REVENUE', 'budget' => 3000, 'provider' => 'meta'])->assertJsonPath('data.status', 'invalid_entity_ownership');
    }

    public function test_ratio_objectives_require_minimum_spend_and_keep_conversion_units(): void
    {
        [$u] = $this->fixture();
        $base = ['entity_level' => 'campaign', 'budget' => 3000, 'provider' => 'meta'];
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', $base + ['objective' => 'MINIMIZE_CPA'])->assertUnprocessable();
        foreach (['MINIMIZE_CPA', 'MAXIMIZE_ROAS'] as $objective) {
            $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', $base + ['objective' => $objective, 'minimum_total_spend' => 1000]);
            $r->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.response_metric', $objective === 'MINIMIZE_CPA' ? 'conversions' : 'conversion_value');
            $this->assertGreaterThanOrEqual(999.99, $r->json('data.scenario_total_spend'));
            $this->assertLessThanOrEqual(3000.01, $r->json('data.scenario_total_spend'));
            $this->assertGreaterThan(0, $r->json('data.ratio_objective_value'));
        }
    }

    public function test_target_cpa_and_roas_have_explicit_target_semantics(): void
    {
        [$u] = $this->fixture();
        foreach ([['TARGET_CPA', 100], ['TARGET_ROAS', 1]] as [$objective,$target]) {
            $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'budget' => 3000, 'minimum_total_spend' => 100, 'provider' => 'meta', 'objective' => $objective, 'target_ratio' => $target]);
            $r->assertOk()->assertJsonPath('data.status', 'ok');
            if ($objective === 'TARGET_CPA') {
                $this->assertLessThanOrEqual($target, $r->json('data.ratio_objective_value'));
            } else {
                $this->assertGreaterThanOrEqual($target, $r->json('data.ratio_objective_value'));
            }
        }
    }

    public function test_entity_archive_replays_all_objectives_after_source_changes(): void
    {
        [$u,$account] = $this->fixture();
        $runs = [];
        foreach (['MAXIMIZE_REVENUE', 'MAXIMIZE_CONVERSIONS', 'MAXIMIZE_ROAS', 'MINIMIZE_CPA', 'TARGET_CPA', 'TARGET_ROAS'] as $objective) {
            $r = $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/entity-optimization', ['entity_level' => 'campaign', 'budget' => 3000, 'minimum_total_spend' => 100, 'provider' => 'meta', 'objective' => $objective, 'target_ratio' => $objective === 'TARGET_CPA' ? 100 : 1])->assertJsonPath('data.status', 'ok')->assertJsonPath('data.archive_status', 'archived');
            $runs[] = $r->json('data.run_id');
        }
        PerformanceMetric::where('connected_ad_account_id', $account->id)->update(['conversions' => 0, 'conversion_value' => 0]);
        $this->travel(40)->days();
        foreach ($runs as $id) {
            $this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay')->assertOk()->assertJsonPath('data.matches_archived_base_result', true);
        }
        $this->travelBack();
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/analytics/optimization-runs/'.$runs[0].'/replay')->assertNotFound();
        $run = OptimizationRun::find($runs[0]);
        $s = $run->source_snapshot;
        $s['channels'][0]['curve']['params']['a'] *= 2;
        $run->update(['source_snapshot' => $s]);
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/analytics/optimization-runs/'.$runs[0].'/replay')->assertStatus(409);
    }
    public function test_external_discrete_menus_archive_and_replay_no_campaign_mutation():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$account,$campaign]=$this->fixture();$options=['entity_level'=>'campaign','objective'=>'MAXIMIZE_CONVERSIONS','budget'=>3000,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1];
        $r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',$options)->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy unavailable');$r->assertJsonPath('data.status','ok')->assertJsonPath('data.certificate.global_optimum_scope','finite sampled menus only')->assertJsonPath('data.archive_status','archived');$id=$r->json('data.run_id');$this->assertSame('ACTIVE',$campaign->fresh()->status);
        PerformanceMetric::where('connected_ad_account_id',$account->id)->update(['conversions'=>0]);$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay')->assertOk()->assertJsonPath('data.matches_archived_base_result',true);
        $this->postJson('/api/v1/analytics/entity-optimization',array_merge($options,['objective'=>'MAXIMIZE_ROAS','minimum_total_spend'=>100]))->assertJsonPath('data.status','unsupported_objective');
    }
    public function test_piecewise_discrete_fit_replays_archived_knots():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this),'analytics.curve_fit.complexity_penalty'=>0.0,'analytics.curve_fit.instability_weight'=>0.0]);[$u,$account]=$this->fixture();$history=PerformanceMetric::where('connected_ad_account_id',$account->id)->orderBy('date')->get();foreach($history as$i=>$row){$x=20+($i%10)*20;$row->update(['spend'=>$x,'conversion_value'=>10*(min($x,100)+.1*max(0,$x-100))]);}
        $r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>6000,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1])->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy unavailable');$r->assertJsonPath('data.status','ok')->assertJsonPath('data.allocations.0.source.family','piecewise');$this->postJson('/api/v1/analytics/optimization-runs/'.$r->json('data.run_id').'/replay')->assertOk()->assertJsonPath('data.matches_archived_base_result',true);
    }
    public function test_entity_comparison_archives_custom_without_campaign_mutation():void{
        [$u,$a,$c]=$this->fixture();$key=$a->id.':'.$c->id;$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta','custom_allocation'=>[$key=>2000]])->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.comparison.custom.status','ok');$this->assertSame(2000.,(float)$r->json('data.comparison.custom.total_spend'));$stored=OptimizationRun::findOrFail($r->json('data.run_id'));$this->assertSame($r->json('data.comparison'),$stored->result['comparison']);$this->assertSame('ACTIVE',$c->fresh()->status);$this->assertNotEquals($r->json('data.actual_response'),$r->json('data.comparison.no_change.modeled_response'));
    }
    public function test_spline_discrete_fit_is_archived_and_replayed():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this),'analytics.curve_fit.complexity_penalty'=>0.,'analytics.curve_fit.instability_weight'=>0.]);[$u,$a]=$this->fixture();$curve=\App\Services\Analytics\Curves\ResponseCurve::quadraticSpline(10,.25,100,300);foreach(PerformanceMetric::where('connected_ad_account_id',$a->id)->orderBy('date')->get()as$i=>$row){$x=20+($i%10)*20;$row->update(['spend'=>$x,'conversion_value'=>$curve->revenueAt($x)]);}$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>6000,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1])->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy unavailable');$r->assertJsonPath('data.status','ok')->assertJsonPath('data.allocations.0.source.family','quadratic_spline')->assertJsonPath('data.sensitivity.status','ok')->assertJsonPath('data.stress.status','ok');$this->postJson('/api/v1/analytics/optimization-runs/'.$r->json('data.run_id').'/replay')->assertOk()->assertJsonPath('data.matches_archived_base_result',true);
    }
    public function test_entity_value_target_minimizes_spend_and_replays_without_current_data():void{
        [$u,$a]=$this->fixture();$this->actingAs($u,'sanctum');$base=['entity_level'=>'campaign','objective'=>'TARGET_REVENUE','budget'=>6000,'provider'=>'meta'];$this->postJson('/api/v1/analytics/entity-optimization',$base)->assertUnprocessable();$r=$this->postJson('/api/v1/analytics/entity-optimization',$base+['target_reported_value'=>10000])->assertOk()->assertJsonPath('data.status','ok');$this->assertGreaterThanOrEqual(10000,$r->json('data.scenario_response'));$this->assertLessThan(6000,$r->json('data.scenario_total_spend'));$this->assertLessThan(.001,$r->json('data.target_search.budget_gap'));PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();$this->postJson('/api/v1/analytics/optimization-runs/'.$r->json('data.run_id').'/replay')->assertOk()->assertJsonPath('data.matches_archived_base_result',true);
    }
    public function test_entity_target_honors_optional_total_minimum_and_rejects_impossible_cap():void{
        [$u]=$this->fixture();$this->actingAs($u,'sanctum');$base=['entity_level'=>'campaign','objective'=>'TARGET_REVENUE','budget'=>6000,'provider'=>'meta','target_reported_value'=>1000];$r=$this->postJson('/api/v1/analytics/entity-optimization',$base+['minimum_total_spend'=>4000])->assertJsonPath('data.status','ok');$this->assertEqualsWithDelta(4000,$r->json('data.scenario_total_spend'),.01);$this->postJson('/api/v1/analytics/entity-optimization',$base+['minimum_total_spend'=>7000])->assertJsonPath('data.status','infeasible');
    }
    public function test_entity_robust_uses_explicit_complete_scale_box_without_overwriting_nominal():void{
        [$u,$a,$c]=$this->fixture();$this->actingAs($u,'sanctum');$key=$a->id.':'.$c->id;$base=['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'];$r=$this->postJson('/api/v1/analytics/entity-optimization',$base+['robust_bounds'=>[$key=>.2]])->assertJsonPath('data.status','ok')->assertJsonPath('data.robust_scenario.status','ok');$nominal=$r->json('data.robust_scenario.nominal_conversion_value');$this->assertEqualsWithDelta(.8*$nominal,$r->json('data.robust_scenario.worst_case_conversion_value'),1e-6);$this->assertEqualsWithDelta(1.2*$nominal,$r->json('data.robust_scenario.best_case_conversion_value'),1e-6);$this->postJson('/api/v1/analytics/entity-optimization',$base+['robust_bounds'=>['unknown'=>.2]])->assertJsonPath('data.robust_scenario.status','not_computable');$this->postJson('/api/v1/analytics/entity-optimization',$base+['robust_bounds'=>[$key=>1]])->assertUnprocessable();$unsupported=$base;$unsupported['objective']='MAXIMIZE_CONVERSIONS';$this->postJson('/api/v1/analytics/entity-optimization',$unsupported+['robust_bounds'=>[$key=>.2]])->assertJsonPath('data.status','ok')->assertJsonPath('data.robust_scenario.status','unsupported_objective');
    }
    public function test_window_uses_completed_reporting_days_not_server_date():void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-23T01:00:00Z'));
        try {
            [$u,$a,$c]=$this->fixture();$a->update(['timezone'=>'America/Los_Angeles']);
            // Shift this fixture by a day: local today is22Nov, server today is23Nov.
            PerformanceMetric::where('campaign_id',$c->id)->orderBy('date')->get()->each(fn($r)=>$r->update(['date'=>$r->date->subDay()->toDateString()]));
            $this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertOk()->assertJsonPath('data.status','ok')->assertJsonPath('data.window.start','2026-10-23')->assertJsonPath('data.window.end','2026-11-21');
            $a->update(['timezone'=>'Not/AZone']);
            $this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000])->assertJsonPath('data.status','incomparable_scope');
        } finally { \Illuminate\Support\Carbon::setTestNow(); }
    }

    public function test_discrete_value_target_minimizes_menu_spend_and_replays_archived_target():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$a,$c]=$this->fixture();$o=['entity_level'=>'campaign','objective'=>'TARGET_REVENUE','budget'=>6000,'provider'=>'meta','target_reported_value'=>10000,'solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1];$this->actingAs($u,'sanctum');$r=$this->postJson('/api/v1/analytics/entity-optimization',$o)->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$r->assertJsonPath('data.status','ok')->assertJsonPath('data.certificate.objective','minimize_spend_for_target_response')->assertJsonPath('data.target_reported_value',10000);$this->assertGreaterThanOrEqual(10000,$r->json('data.scenario_response'));$this->assertLessThan(6000,$r->json('data.scenario_total_spend'));PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();$this->postJson('/api/v1/analytics/optimization-runs/'.$r->json('data.run_id').'/replay')->assertJsonPath('data.matches_archived_base_result',true);
    }

    public function test_discrete_cpa_and_roas_targets_preserve_units_and_replay():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$a]=$this->fixture();$this->actingAs($u,'sanctum');$ids=[];
        foreach(['TARGET_CPA'=>5,'TARGET_ROAS'=>3]as$objective=>$target){$r=$this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>$objective,'target_ratio'=>$target,'minimum_total_spend'=>500,'budget'=>3000,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>250,'maximum_active'=>1])->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$r->assertJsonPath('data.status','ok')->assertJsonPath('data.response_metric',$objective==='TARGET_CPA'?'conversions':'conversion_value');$ratio=$r->json('data.ratio_objective_value');$objective==='TARGET_CPA'?$this->assertLessThanOrEqual($target,$ratio):$this->assertGreaterThanOrEqual($target,$ratio);$ids[]=$r->json('data.run_id');}
        PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();foreach($ids as$id)$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay')->assertJsonPath('data.matches_archived_base_result',true);
    }

    public function test_discrete_box_robust_scenario_preserves_selected_menu_semantics_and_archive():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$a,$c]=$this->fixture();$key=$a->id.':'.$c->id;$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'minimum_total_spend'=>500,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1,'robust_bounds'=>[$key=>.2]])->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$r->assertJsonPath('data.robust_scenario.status','ok')->assertJsonPath('data.robust_scenario.solver','discrete_milp')->assertJsonPath('data.robust_scenario.certificate.global_optimum_scope','finite sampled menus only');$this->assertEqualsWithDelta(.8*$r->json('data.robust_scenario.nominal_conversion_value'),$r->json('data.robust_scenario.worst_case_conversion_value'),1e-6);$run=OptimizationRun::findOrFail($r->json('data.run_id'));$this->assertSame($r->json('data.robust_scenario'),$run->result['robust_scenario']);
    }

    public function test_robust_replay_uses_archived_curves_bounds_and_configuration_in_both_modes():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$a,$c]=$this->fixture();$key=$a->id.':'.$c->id;$this->actingAs($u,'sanctum');$ids=[];
        foreach(['continuous','discrete_milp']as$solver){$o=['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta','solver'=>$solver,'budget_step'=>500,'maximum_active'=>1,'robust_bounds'=>[$key=>.2]];$r=$this->postJson('/api/v1/analytics/entity-optimization',$o)->assertOk();if(in_array($r->json('data.status'),['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$r->assertJsonPath('data.robust_scenario.status','ok');$ids[]=$r->json('data.run_id');}
        PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();config(['or.maintain_band_percent'=>99]);
        foreach($ids as$id){$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay',['scenario'=>'robust'])->assertOk()->assertJsonPath('data.scenario','robust')->assertJsonPath('data.matches_archived_robust_result',true);$this->assertSame(99,config('or.maintain_band_percent'));}
        $other=User::factory()->create();$this->actingAs($other,'sanctum')->postJson('/api/v1/analytics/optimization-runs/'.$ids[0].'/replay',['scenario'=>'robust'])->assertNotFound();
    }
    public function test_requesting_absent_robust_archive_does_not_replay_base_instead():void{
        [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','ok');$id=$r->json('data.run_id');$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay',['scenario'=>'robust'])->assertJsonPath('data.status','not_computable');$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay',['scenario'=>'unknown'])->assertUnprocessable();
    }

    public function test_continuous_target_sensitivity_preserves_objective_and_is_archived():void{
        [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'TARGET_REVENUE','target_reported_value'=>10000,'budget'=>6000,'provider'=>'meta'])->assertOk()->assertJsonPath('data.sensitivity.status','ok')->assertJsonPath('data.sensitivity.objective','TARGET_REVENUE')->assertJsonPath('data.sensitivity.solver','continuous')->assertJsonPath('data.response_profiles.status','ok')->assertJsonCount(1,'data.response_profiles.rows');$this->assertSame($r->json('data.response_profiles'),OptimizationRun::findOrFail($r->json('data.run_id'))->result['response_profiles']);$this->assertSame($r->json('data.sensitivity'),OptimizationRun::findOrFail($r->json('data.run_id'))->result['sensitivity']);
    }

    public function test_closed_question_explanation_is_owner_scoped_archived_and_not_live():void{
        [$u,$a]=$this->fixture();$this->actingAs($u,'sanctum');$r=$this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_CONVERSIONS','budget'=>3000,'provider'=>'meta'])->assertOk();$id=$r->json('data.run_id');PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();$url='/api/v1/analytics/optimization-runs/'.$id.'/explain';$this->postJson($url,['question'=>'what_is_optimized'])->assertOk()->assertJsonPath('data.evidence.response_metric','conversions')->assertJsonPath('data.evidence.modeled_response',$r->json('data.scenario_response'));$this->postJson($url,['question'=>'invent_roi'])->assertStatus(422);$run=OptimizationRun::findOrFail($id);$run->update(['snapshot_sha256'=>str_repeat('0',64)]);$this->postJson($url,['question'=>'what_are_limits'])->assertStatus(409);$other=User::factory()->create();$this->actingAs($other,'sanctum')->postJson($url,['question'=>'what_are_limits'])->assertNotFound();
    }

    public function test_discrete_custom_off_menu_spend_remains_visible_but_infeasible():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);[$u,$a,$c]=$this->fixture();$key=$a->id.':'.$c->id;$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta','solver'=>'discrete_milp','budget_step'=>500,'maximum_active'=>1,'custom_allocation'=>[$key=>1750]])->assertOk()->assertJsonPath('data.comparison.custom.status','infeasible')->assertJsonPath('data.comparison.custom.menu_membership_checked',true)->assertJsonPath('data.comparison.custom.constraint_violations.0','off_menu:'.$key)->assertJsonPath('data.comparison.custom.total_spend',1750);
    }

    public function test_entity_input_and_result_integrity_blocks_replay_and_explanation():void{
        [$u]=$this->fixture();$this->actingAs($u,'sanctum');$r=$this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_CONVERSIONS','budget'=>3000,'provider'=>'meta'])->assertOk();$run=OptimizationRun::findOrFail($r->json('data.run_id'));$this->assertSame('matches',$run->integrityStatus());$inputs=$run->inputs;$inputs['budget']=9000;$run->update(['inputs'=>$inputs]);$url='/api/v1/analytics/optimization-runs/'.$run->id;$this->postJson($url.'/replay')->assertStatus(409);$this->postJson($url.'/explain',['question'=>'what_is_optimized'])->assertStatus(409);$this->getJson($url)->assertJsonPath('data.result_integrity','mismatch');
    }

    public function test_stored_html_report_is_owner_only_escaped_and_has_no_raw_source_payload():void{
        [$u,$a]=$this->fixture();$this->actingAs($u,'sanctum');$r=$this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_CONVERSIONS','budget'=>3000,'provider'=>'meta'])->assertOk();$id=$r->json('data.run_id');$url='/api/v1/analytics/optimization-runs/'.$id.'/report';$this->get($url)->assertOk()->assertHeader('Content-Disposition','attachment; filename="adplatform-entity-run-'.$id.'.html"')->assertSee('ARCHIVED SCENARIO')->assertSee('Modeled conversions')->assertDontSee('access_token')->assertDontSee('source_rows');$run=OptimizationRun::findOrFail($id);$result=$run->result;$result['limits']=['<script>alert(1)</script>'];$run->result=$result;$run->archive_integrity_sha256=$run->integrityDigest();$run->save();$this->get($url)->assertOk()->assertSee('&lt;script&gt;',false)->assertDontSee('<script>',false);$run->update(['result'=>$r->json('data')]);$this->get($url)->assertStatus(409);$this->actingAs(User::factory()->create(),'sanctum')->get($url)->assertNotFound();
    }

 public function test_supported_source_archive_new_result_integrity_change_is_detected():void{
  [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','ok');$run=OptimizationRun::findOrFail($r->json('data.run_id'));$result=$run->result;$result['scenario_response']+=1;$run->update(['result'=>$result]);$this->assertSame('mismatch',$run->fresh()->integrityStatus());$this->postJson('/api/v1/analytics/optimization-runs/'.$run->id.'/replay')->assertStatus(409);
 }
 public function test_supported_source_archive_input_integrity_change_is_detected():void{
  [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','ok');$run=OptimizationRun::findOrFail($r->json('data.run_id'));$in=$run->inputs;$in['budget']+=1;$run->update(['inputs'=>$in]);$this->assertSame('mismatch',$run->fresh()->integrityStatus());$this->postJson('/api/v1/analytics/optimization-runs/'.$run->id.'/replay')->assertStatus(409);
 }
 public function test_supported_source_archive_legacy_unsealed_does_not_backfill_integrity():void{
  [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','ok');\Illuminate\Support\Facades\DB::table('optimization_runs')->where('id',$r->json('data.run_id'))->update(['archive_integrity_sha256'=>null]);$run=OptimizationRun::findOrFail($r->json('data.run_id'));$this->assertSame('legacy_unsealed',$run->integrityStatus());$this->getJson('/api/v1/analytics/optimization-runs/'.$run->id)->assertOk()->assertJsonPath('data.result_integrity','legacy_unsealed');$this->assertNull($run->fresh()->archive_integrity_sha256);
 }
 public function test_supported_source_archive_foreign_owner_cannot_read_or_replay():void{
  [$u]=$this->fixture();$r=$this->actingAs($u,'sanctum')->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','ok');$id=$r->json('data.run_id');$this->actingAs(User::factory()->create(),'sanctum')->getJson('/api/v1/analytics/optimization-runs/'.$id)->assertNotFound();$this->postJson('/api/v1/analytics/optimization-runs/'.$id.'/replay')->assertNotFound();
 }
}
