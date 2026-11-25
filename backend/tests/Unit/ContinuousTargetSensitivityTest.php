<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\ContinuousTargetSensitivity;
final class ContinuousTargetSensitivityTest extends TestCase{
 private function channels():array{return [['channel'=>'a','display_name'=>'A','min'=>0,'max'=>100,'current_spend'=>50,'current_revenue'=>50,'curve'=>ResponseCurve::linear(1,100)]];}
 public function test_target_spend_uses_minimum_value_objective_and_budget_cap_shares():void{
  config(['analytics.scenarios.perturbation_percent'=>10]);$r=(new ContinuousTargetSensitivity)->analyze($this->channels(),['objective'=>'TARGET_REVENUE','budget'=>100,'target_reported_value'=>50]);$this->assertSame('ok',$r['status']);$this->assertSame('TARGET_REVENUE',$r['objective']);$this->assertEqualsWithDelta(5.56,$r['max_share_shift_pp'],.02);
 }
 public function test_continuous_ratio_target_withholds_stability_when_perturbed_target_is_infeasible():void{
  config(['analytics.scenarios.perturbation_percent'=>10]);foreach(['TARGET_ROAS'=>.95,'TARGET_CPA'=>1/.95]as$o=>$target){$r=(new ContinuousTargetSensitivity)->analyze($this->channels(),['objective'=>$o,'budget'=>100,'minimum_total_spend'=>10,'target_ratio'=>$target]);$this->assertSame('partial',$r['status']);$this->assertSame(1,$r['failed_perturbations']);$this->assertNull($r['stability']);$this->assertSame($o,$r['objective']);}
 }
 public function test_unconstrained_ratio_objective_retains_explicit_spend_floor():void{
  config(['analytics.scenarios.perturbation_percent'=>10]);foreach(['MAXIMIZE_ROAS','MINIMIZE_CPA']as$o){$chs=$this->channels();$chs[0]['curve']=ResponseCurve::saturation(100,.02,100);$r=(new ContinuousTargetSensitivity)->analyze($chs,['objective'=>$o,'budget'=>100,'minimum_total_spend'=>10]);$this->assertSame('ok',$r['status']);$this->assertEqualsWithDelta(0,$r['max_share_shift_pp'],.01);$this->assertSame('continuous',$r['solver']);}
 }
 public function test_population_cap_abstains():void{$chs=array_merge($this->channels(),$this->channels(),$this->channels(),$this->channels());$this->assertSame('not_computable',(new ContinuousTargetSensitivity)->analyze($chs,['objective'=>'TARGET_REVENUE','budget'=>100])['status']);}
}
