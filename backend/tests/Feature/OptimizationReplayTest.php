<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,PerformanceMetric,OptimizationRun};
use App\Services\OperationsResearch\OptimizationOrchestrator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
/** Cross-provider portfolio integration is now a refusal regression. Pure/entity solver tests retain success coverage. */
class OptimizationReplayTest extends TestCase {
 use DatabaseTransactions;
 public function test_previously_declared_cross_provider_archive_is_withheld_without_resealing():void{
  $u=User::factory()->create();$rows=[];foreach(['meta','google']as$p)$rows[]=['provider'=>$p,'metadata'=>['normalization_version'=>'strict-operands-1','account_metadata_source'=>'provider-discovery-explicit-1','metric_definition'=>['version'=>'meta-pixel-purchase-1','normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1','conversion'=>'offsite_conversion.fb_pixel_purchase','value'=>'offsite_conversion.fb_pixel_purchase'],'outcome_contract'=>['version'=>'source-outcomes-1','provider'=>$p,'count_metric'=>'same','value_metric'=>'same','attribution_basis'=>'same','value_truth'=>'provider_reported_not_verified_sales','cross_source_equivalence'=>false]]];
  $snapshot=['source_rows'=>$rows];$run=OptimizationRun::create(['user_id'=>$u->id,'engine'=>'adplatform-or','engine_version'=>'7.4.1','status'=>'ok','input_hash'=>str_repeat('a',64),'inputs'=>[],'dataset_fingerprint'=>[],'source_snapshot'=>$snapshot,'snapshot_sha256'=>hash('sha256',json_encode($snapshot)),'result'=>['status'=>'ok','optimal_total_revenue'=>123]]);$run->refresh();$before=$run->getAttributes();
  $this->actingAs($u,'sanctum')->getJson('/api/v1/analytics/optimization-runs/'.$run->id)->assertOk()->assertJsonPath('data.result.stored_output_withheld',true)->assertJsonMissingPath('data.result.optimal_total_revenue');$this->postJson('/api/v1/analytics/optimization-runs/'.$run->id.'/replay')->assertOk()->assertJsonPath('data.status','not_computable');$this->assertSame($before,$run->fresh()->getAttributes());$other=User::factory()->create();$this->actingAs($other,'sanctum')->getJson('/api/v1/analytics/optimization-runs/'.$run->id)->assertNotFound();
 }
}
