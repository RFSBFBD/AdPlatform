<?php
namespace Tests\Unit;
use App\Services\Advertising\AnalystEvidence;
use Tests\TestCase;
class AnalystEvidenceTest extends TestCase
{
 public function test_six_blocks_abstain_instead_of_turning_failed_dto_zeros_into_actuals():void{
  $r=(new AnalystEvidence)->build(['status'=>'insufficient_data','current_total_revenue'=>0,'optimal_total_revenue'=>0],'snap');
  $this->assertSame(['actual_metrics','forecast','optimization','diagnostics','confidence','data_quality'],array_keys($r));$this->assertSame([],$r['actual_metrics']['channels']);$this->assertNull($r['optimization']['modeled_value']);$this->assertNull($r['confidence']['allocation_confidence']);$this->assertSame('not_computed_for_this_snapshot',$r['forecast']['status']);
 }
 public function test_scenario_and_actual_operands_stay_separate_and_private_fields_are_not_forwarded():void{
  $r=(new AnalystEvidence)->build(['status'=>'ok','optimal_total_revenue'=>900,'access_token'=>'private-token','channel_allocations'=>[['channel'=>'meta','current_spend'=>100,'current_revenue'=>300,'optimal_spend'=>200,'optimal_revenue'=>900,'baseline_spend_same_budget'=>200,'name'=>'private-name','access_token'=>'private-token']]],'snap');
  $this->assertSame(300,$r['actual_metrics']['channels'][0]['platform_reported_value']);$this->assertSame(900,$r['optimization']['allocations'][0]['modeled_value']);$this->assertStringNotContainsString('private',json_encode($r));$this->assertSame('snap',$r['actual_metrics']['snapshot_id']);
 }
}
