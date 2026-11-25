<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,PerformanceMetric,OptimizationRun};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
final class LegacyMetaAnalysisBoundaryTest extends TestCase{
 use DatabaseTransactions;
 public function test_legacy_only_daily_and_optimizer_paths_are_blocked_and_stored_rows_unchanged():void{
  $u=User::factory()->create();$a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>'c','name'=>'Synthetic','currency'=>'USD']);for($i=30;$i>=1;$i--)PerformanceMetric::create(['connected_ad_account_id'=>$a->id,'campaign_id'=>$c->id,'provider'=>'meta','currency'=>'USD','date'=>now()->subDays($i)->toDateString(),'spend'=>100+$i,'impressions'=>1000,'clicks'=>10,'conversions'=>5,'conversion_value'=>200]);$before=PerformanceMetric::where('connected_ad_account_id',$a->id)->orderBy('id')->get()->toArray();$this->actingAs($u,'sanctum');
  foreach(['/kpis','/forecast?provider=meta&metric=spend','/anomalies?provider=meta&metric=spend','/diagnostics?provider=meta','/observed-series?provider=meta']as$p)$this->assertStringContainsString('Legacy, missing or differing Meta outcome definitions',$this->getJson('/api/v1/analytics'.$p)->assertOk()->json('data.reason'));
  foreach(['/api/v1/analytics/overview','/api/v1/analytics/campaigns','/api/v1/analytics/campaigns/'.$c->id,'/api/v1/reporting/looker-studio']as$url)$this->getJson($url)->assertStatus(409);
  $this->getJson('/api/v1/analytics/audience-matrix?account_id='.$a->id.'&entity_level=campaign')->assertJsonPath('data.status','insufficient_data');
  $this->getJson('/api/v1/analytics/campaign-health?provider=meta')->assertJsonPath('data.rows.0.diagnostic.decision_state','INSUFFICIENT_DATA');
  foreach(['assumed','fitted']as$mode)$this->getJson('/api/v1/analytics/operations-research?objective=MAXIMIZE_REPORTED_VALUE&outcome_basis=platform_reported_credit&budget=3000&budget_horizon=per_30_days&curve_mode='.$mode.'&saturation_multiplier=2')->assertOk()->assertJsonPath('data.status','incomparable_metric_definitions');
  $this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_REVENUE','budget'=>3000,'provider'=>'meta'])->assertJsonPath('data.status','incomparable_metric_definitions');$this->assertSame($before,PerformanceMetric::where('connected_ad_account_id',$a->id)->orderBy('id')->get()->toArray());
 }
 public function test_legacy_archive_is_retained_but_not_replayed_explained_exported_or_displayed_as_a_decision():void{
  $u=User::factory()->create();$snapshot=['source_rows'=>[['provider'=>'meta','metadata'=>null]],'channels'=>[]];$r=OptimizationRun::create(['user_id'=>$u->id,'engine'=>'entity','engine_version'=>'entity-2.7','model_name'=>'fixture','model_version'=>'fixture','status'=>'ok','input_hash'=>'fixture','dataset_fingerprint'=>[],'inputs'=>['objective'=>'MAXIMIZE_REVENUE'],'result'=>['status'=>'ok','scenario_response'=>99999],'source_snapshot'=>$snapshot,'snapshot_sha256'=>hash('sha256',json_encode($snapshot))]);$before=$r->fresh()->getAttributes();$this->actingAs($u,'sanctum');$base='/api/v1/analytics/optimization-runs/'.$r->id;
  $this->getJson($base)->assertOk()->assertJsonPath('data.result.status','not_computable')->assertJsonPath('data.result.stored_output_withheld',true);$this->postJson($base.'/replay')->assertOk()->assertJsonPath('data.status','not_computable');$this->postJson($base.'/explain',['question'=>'what_is_optimized'])->assertOk()->assertJsonPath('data.status','not_computable');$this->get($base.'/report')->assertStatus(409);$this->postJson($base.'/compare',['other_run_id'=>$r->id])->assertOk()->assertJsonPath('data.status','not_computable');$this->assertSame($before,$r->fresh()->getAttributes());
 }
}
