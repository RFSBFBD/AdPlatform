<?php
namespace Tests\Feature;
use App\Models\{User,ConnectedAdAccount,Campaign,PerformanceMetric,OptimizationRun,TypedConversionDaily};
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GuidedLeadScenarioTest extends TestCase
{
 use DatabaseTransactions;
 private function workflow():array
 {
  (new RoleAndPermissionSeeder)->run();$u=User::factory()->create();$u->assignRole('Owner');
  $a=ConnectedAdAccount::create(['user_id'=>$u->id,'provider'=>'meta','external_account_id'=>uniqid(),'account_name'=>'Synthetic lead account','currency'=>'USD','timezone'=>'UTC','access_token'=>'fixture']);
  $this->actingAs($u,'sanctum');
  $this->getJson('/api/v1/ad-accounts')->assertOk();
  $d=$this->postJson('/api/v1/ad-accounts/'.$a->id.'/conversion-definitions',['external_key'=>'declared','name'=>'Declared test leads','kind'=>'lead','counting_method'=>'events','attribution_model'=>'declared_last_click','click_window_days'=>7,'view_window_days'=>0,'source_system'=>'synthetic_crm'])->assertCreated()->json('data');
  $rows=[];$campaigns=[];
  foreach([1,2]as$j){$c=Campaign::create(['connected_ad_account_id'=>$a->id,'provider'=>'meta','external_campaign_id'=>uniqid(),'name'=>'Synthetic campaign '.$j,'currency'=>'USD']);$campaigns[]=$c;
   for($i=30;$i>=1;$i--){$x=30+(30-$i)*10;$date=now()->subDays($i)->toDateString();
    // Deliberate local source fixture, not a provider fetch or spend-semantic verification.
    PerformanceMetric::create(['metadata'=>['normalization_version'=>'strict-operands-1','metric_definition'=>['normalization'=>'strict-meta-operands-1','account_metadata_source'=>'meta-discovery-explicit-1']],'connected_ad_account_id'=>$a->id,'campaign_id'=>$c->id,'provider'=>'meta','date'=>$date,'spend'=>$x,'impressions'=>1000,'clicks'=>100,'conversions'=>9999,'conversion_value'=>999999,'currency'=>'USD']);
    $rows[]=['entity_id'=>$c->id,'date'=>$date,'count'=>round((30+20*$j)*(1-exp(-.01*$x))),'currency'=>'USD','timezone'=>'UTC','source_reference'=>'synthetic-batch','retrieved_at'=>now()->format('Y-m-d\TH:i:sP')];
   }
  }
  $this->postJson('/api/v1/ad-accounts/'.$a->id.'/conversion-definitions/'.$d['id'].'/daily-import',['entity_level'=>'campaign','rows'=>$rows])->assertOk()->assertJsonPath('data.provider_verified',false);
  $r=$this->postJson('/api/v1/analytics/entity-optimization',['entity_level'=>'campaign','objective'=>'MAXIMIZE_LEADS','budget'=>8000,'provider'=>'meta','conversion_definition_id'=>$d['id'],'solver'=>'continuous'])->assertOk()->assertJsonPath('data.status','ok')->assertJsonCount(2,'data.allocations')->json('data');
  $this->assertEqualsCanonicalizing(['Synthetic campaign 1','Synthetic campaign 2'],array_column(array_column($r['allocations'],'source'),'entity_name'));
  return [$u,$a,$r,OptimizationRun::findOrFail($r['run_id'])];
 }
 private function independent(array $snapshot,array $allocation):float
 {
  $total=0.;foreach($snapshot['channels']as$c){$x=$allocation[$c['channel']];$curve=$c['curve'];$p=$curve['params'];
   $total+=match($curve['name']){'linear'=>$p['a']*$x,'log'=>$p['a']*log(1+$p['b']*$x),'power'=>$p['a']*pow($x,$p['b']),'saturation'=>$p['a']*(1-exp(-$p['b']*$x)),default=>throw new \RuntimeException('Add independent formula for '.$curve['name'])};
  }return $total;
 }
 public function test_api_workflow_evaluates_custom_on_frozen_curves_independently_without_refit_or_archive_write():void
 {
  [$u,$a,$r,$run]=$this->workflow();$keys=array_column($r['allocations'],'entity_key');$alloc=[$keys[0]=>3000,$keys[1]=>4000];$before=$run->getAttributes();$count=OptimizationRun::count();$url='/api/v1/analytics/optimization-runs/'.$run->id.'/custom-leads';
  $response=$this->postJson($url,['custom_allocation'=>$alloc])->assertOk()->assertJsonPath('data.status','evaluated')->assertJsonPath('data.custom.status','ok')->assertJsonPath('data.custom.total_spend',7000)->assertJsonPath('data.remaining_budget',1000)->assertJsonPath('data.snapshot_sha256',$r['snapshot_sha256'])->json('data');
  $expected=$this->independent($run->source_snapshot,$alloc);$this->assertEqualsWithDelta($expected,$response['custom']['modeled_response'],1e-8);$this->assertEqualsWithDelta($expected-$r['scenario_response'],$response['modeled_difference_vs_optimized'],1e-8);
  PerformanceMetric::where('connected_ad_account_id',$a->id)->delete();TypedConversionDaily::where('conversion_definition_id',$run->inputs['conversion_definition_id'])->delete();$a->update(['currency'=>'EUR']);config(['or.maintain_band_percent'=>99,'analytics.data_quality.optimize_threshold'=>999]);
  $this->travel(10)->days();try{$again=$this->postJson($url,['custom_allocation'=>$alloc])->assertOk()->json('data');$this->assertEquals($response,$again);$this->postJson('/api/v1/analytics/optimization-runs/'.$run->id.'/replay')->assertJsonPath('data.matches_archived_base_result',true);}finally{$this->travelBack();}
  $this->assertEquals($before,$run->fresh()->getAttributes());$this->assertSame($count,OptimizationRun::count());
  file_put_contents('/tmp/guided-lead-api-fixture.json',json_encode(['run'=>$r,'custom'=>$response,'accounts'=>[['id'=>$a->id,'account_name'=>'Synthetic lead account','provider'=>'meta','currency'=>'USD','timezone'=>'UTC']],'definitions'=>[['id'=>$run->inputs['conversion_definition_id'],'name'=>'Declared test leads','kind'=>'lead','counting_method'=>'events','source_system'=>'synthetic_crm','attribution_model'=>'declared_last_click','click_window_days'=>7,'view_window_days'=>0]]],JSON_PRETTY_PRINT));
 }
 public function test_custom_scope_constraints_owner_and_integrity_are_enforced():void
 {
  [$u,$a,$r,$run]=$this->workflow();$keys=array_column($r['allocations'],'entity_key');$url='/api/v1/analytics/optimization-runs/'.$run->id.'/custom-leads';
  $this->postJson($url,['custom_allocation'=>[$keys[0]=>100]])->assertJsonPath('data.status','not_computable');
  $this->postJson($url,['custom_allocation'=>[$keys[0]=>100,$keys[1]=>100,'foreign'=>1]])->assertJsonPath('data.status','not_computable');
  $this->postJson($url,['custom_allocation'=>[$keys[0]=>-1,$keys[1]=>100]])->assertUnprocessable();
  $this->postJson($url,['custom_allocation'=>[$keys[0]=>9000,$keys[1]=>1000]])->assertJsonPath('data.custom.status','infeasible')->assertJsonFragment(['budget_cap'])->assertJsonFragment(['entity_bounds:'.$keys[0]]);
  $zero=$this->postJson($url,['custom_allocation'=>[$keys[0]=>0,$keys[1]=>0]])->assertJsonPath('data.custom.modeled_response',0)->json('data');$this->assertTrue($zero['custom']['allocation'][0]['outside_observed_support']);
  $this->actingAs(User::factory()->create(),'sanctum')->postJson($url,['custom_allocation'=>[$keys[0]=>1,$keys[1]=>1]])->assertNotFound();$this->actingAs($u,'sanctum');
  $run->update(['result'=>array_replace($run->result,['scenario_response'=>999999])]);$this->postJson($url,['custom_allocation'=>[$keys[0]=>1,$keys[1]=>1]])->assertJsonPath('data.status','not_computable');
 }

 public function test_custom_refuses_altered_source_and_wrong_archive_branch_and_direct_invalid_numbers():void
 {
  [$u,$a,$r,$run]=$this->workflow();$keys=array_column($r['allocations'],'entity_key');$alloc=[$keys[0]=>100,$keys[1]=>100];$service=new \App\Services\OperationsResearch\FrozenLeadScenario;
  $this->assertSame('not_computable',$service->evaluate($run,[$keys[0]=>INF,$keys[1]=>100])['status']);
  $snapshot=$run->source_snapshot;$changed=$snapshot;$changed['channels'][0]['curve']['params']['a']*=2;$run->source_snapshot=$changed;$this->assertSame('not_computable',$service->evaluate($run,$alloc)['status']);$run->source_snapshot=$snapshot;
  $run->inputs=array_replace($run->inputs,['objective'=>'MAXIMIZE_REVENUE']);$this->assertSame('not_computable',$service->evaluate($run,$alloc)['status']);
 }
}
