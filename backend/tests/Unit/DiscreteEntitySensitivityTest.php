<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\DiscreteEntitySensitivity;
final class DiscreteEntitySensitivityTest extends TestCase{
 private function setupSolver():void{config(['solver.python'=>\Tests\SolverRuntime::python($this),'analytics.scenarios.perturbation_percent'=>10]);}
 private function channels():array{return [['channel'=>'a','min'=>0,'max'=>100,'curve'=>ResponseCurve::linear(1,100)],['channel'=>'b','min'=>0,'max'=>100,'curve'=>ResponseCurve::linear(.95,100)]];}
 private function solverOptions(string $objective='MAXIMIZE_REVENUE'):array{return ['objective'=>$objective,'budget'=>100,'budget_step'=>50,'maximum_active'=>1,'minimum_total_spend'=>50];}
 public function test_menu_sensitivity_matches_enumerated_switches_with_active_cap():void{
  $this->setupSolver();$r=(new DiscreteEntitySensitivity)->analyze($this->channels(),$this->solverOptions());$this->assertSame('ok',$r['status']);$this->assertSame('discrete_milp',$r['solver']);$this->assertSame(100.,$r['max_share_shift_pp']);$this->assertCount(4,$r['runs']);foreach($r['runs']as$i=>$run){$this->assertSame(in_array($i,[1,2])?100.:0.,$run['max_share_shift_pp']);}
 }
 public function test_ratio_infeasible_perturbation_withholds_stability_instead_of_unconstrained_fallback():void{
  $this->setupSolver();$chs=[$this->channels()[0]];$r=(new DiscreteEntitySensitivity)->analyze($chs,$this->solverOptions('TARGET_ROAS')+['target_ratio'=>.95]);$this->assertSame('partial',$r['status']);$this->assertNull($r['stability']);$this->assertSame(1,$r['failed_perturbations']);$this->assertSame('TARGET_ROAS',$r['objective']);$this->assertSame('infeasible',$r['runs'][1]['status']);
 }
 public function test_value_target_reoptimizes_minimum_menu_spend_not_response_maximization():void{
  $this->setupSolver();$r=(new DiscreteEntitySensitivity)->analyze([$this->channels()[0]],$this->solverOptions('TARGET_REVENUE')+['target_reported_value'=>50]);$this->assertSame('ok',$r['status']);$this->assertSame(50.,$r['max_share_shift_pp']);$this->assertSame('TARGET_REVENUE',$r['objective']);
 }
 public function test_local_cap_abstains_before_solver_use():void{
  config(['solver.python'=>null]);$r=(new DiscreteEntitySensitivity)->analyze(array_merge($this->channels(),$this->channels()),$this->solverOptions());$this->assertSame('not_computable',$r['status']);$this->assertNull($r['stability']);
 }
}
