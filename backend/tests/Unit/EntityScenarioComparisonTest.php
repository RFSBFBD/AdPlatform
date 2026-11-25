<?php
namespace Tests\Unit;
use App\Services\OperationsResearch\EntityScenarioComparison;
use App\Services\Analytics\Curves\ResponseCurve;
use Tests\TestCase;
class EntityScenarioComparisonTest extends TestCase{
 private function channels():array{return [['channel'=>'a','curve'=>ResponseCurve::linear(2,200),'current_spend'=>100,'min'=>0,'max'=>300],['channel'=>'b','curve'=>ResponseCurve::linear(1,200),'current_spend'=>100,'min'=>20,'max'=>300]];}
 public function test_no_change_and_same_spend_are_curve_evaluations_with_exact_operands():void{
  $r=(new EntityScenarioComparison)->build($this->channels(),['budget'=>400,'objective'=>'MAXIMIZE_REVENUE'],['scenario_total_spend'=>400,'scenario_response'=>700]);$this->assertSame(300.,$r['no_change']['modeled_response']);$this->assertSame(200.,$r['no_change']['total_spend']);$this->assertSame(600.,$r['same_scenario_spend_baseline']['modeled_response']);$this->assertSame(100.,$r['modeled_gain_vs_same_spend_baseline']);
 }
 public function test_custom_scope_bounds_cap_active_minimum_and_target_violations_are_visible():void{
  $s=new EntityScenarioComparison;$base=['budget'=>200,'objective'=>'MAXIMIZE_REVENUE','solver'=>'discrete_milp','budget_step'=>50,'maximum_active'=>1,'minimum_total_spend'=>100];$result=['scenario_total_spend'=>200,'scenario_response'=>400];$r=$s->build($this->channels(),$base+['custom_allocation'=>['a'=>100,'b'=>100]],$result);$this->assertSame('infeasible',$r['custom']['status']);$this->assertContains('maximum_active',$r['custom']['constraint_violations']);$r=$s->build($this->channels(),$base+['custom_allocation'=>['a'=>100,'b'=>0]],$result);$this->assertSame('ok',$r['custom']['status']);$this->assertTrue($r['custom']['menu_membership_checked']);$r=$s->build($this->channels(),$base+['custom_allocation'=>['a'=>100]],$result);$this->assertSame('invalid_scope',$r['custom']['status']);$r=$s->build($this->channels(),['budget'=>200,'objective'=>'TARGET_ROAS','target_ratio'=>3,'custom_allocation'=>['a'=>100,'b'=>0]],$result);$this->assertContains('target_ratio',$r['custom']['constraint_violations']);$this->assertContains('entity_bounds:b',$r['custom']['constraint_violations']);
 }
 public function test_off_menu_fixed_scenario_is_not_labeled_feasible():void{$r=(new EntityScenarioComparison)->build($this->channels(),['budget'=>200,'objective'=>'MAXIMIZE_REVENUE','solver'=>'discrete_milp','budget_step'=>50,'maximum_active'=>2,'custom_allocation'=>['a'=>75,'b'=>25]],['scenario_total_spend'=>200,'scenario_response'=>400]);$this->assertSame('infeasible',$r['custom']['status']);$this->assertContains('off_menu:a',$r['custom']['constraint_violations']);$this->assertContains('off_menu:b',$r['custom']['constraint_violations']);$this->assertSame(175.,$r['custom']['modeled_response']);}
}
