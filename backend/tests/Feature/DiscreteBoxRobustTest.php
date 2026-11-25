<?php
namespace Tests\Feature;
use App\Services\OperationsResearch\{DiscreteBoxRobustOptimizer,DiscreteBudgetOptimizer};
use App\Services\Analytics\Curves\ResponseCurve;
use Tests\TestCase;
class DiscreteBoxRobustTest extends TestCase{
 private function channels():array{return [['channel'=>'a','curve'=>ResponseCurve::saturation(100,.01,300),'min'=>50,'max'=>200],['channel'=>'b','curve'=>ResponseCurve::piecewise(1,.2,100,300),'min'=>25,'max'=>200]];}
 public function test_box_counterpart_matches_enumerated_worst_case_with_binary_activation():void{config(['solver.python'=>\Tests\SolverRuntime::python($this)]);$chs=$this->channels();$r=(new DiscreteBoxRobustOptimizer)->optimize($chs,250,['a'=>.5,'b'=>.1],25,1,50);if(in_array($r['status'],['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$this->assertSame('ok',$r['status'],json_encode($r));$nom=(new DiscreteBudgetOptimizer)->optimize($chs,250,25,1,50);$best=-INF;foreach($nom['menus'][0]['choices']as$a)foreach($nom['menus'][1]['choices']as$b){$sp=$a['spend']+$b['spend'];$active=($a['spend']>0)+($b['spend']>0);if($sp>=50&&$sp<=250&&$active<=1)$best=max($best,.5*$a['response']+.9*$b['response']);}$this->assertEqualsWithDelta($best,$r['worst_case_conversion_value'],1e-6);$this->assertSame(1,$r['active_entities']);$this->assertEqualsWithDelta($r['nominal_conversion_value'],$r['channels'][0]['nominal_conversion_value']+$r['channels'][1]['nominal_conversion_value'],1e-8);$this->assertSame('finite sampled menus only',$r['certificate']['global_optimum_scope']);}
 public function test_missing_extra_and_invalid_bounds_fail_closed():void{$s=new DiscreteBoxRobustOptimizer;$this->assertSame('not_computable',$s->optimize($this->channels(),250,['a'=>.2],25,1)['status']);$this->assertSame('invalid_uncertainty',$s->optimize($this->channels(),250,['a'=>.2,'b'=>1],25,1)['status']);}
}
