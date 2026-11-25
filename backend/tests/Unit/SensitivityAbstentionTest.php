<?php
namespace Tests\Unit;
use App\Services\Analytics\ScenarioEngine;
use App\Services\Analytics\Curves\ResponseCurve;
use Tests\TestCase;
class SensitivityAbstentionTest extends TestCase{
 public function test_failed_perturbations_do_not_create_false_stable_label():void{
  $n=0;$r=(new ScenarioEngine)->sensitivity([['channel'=>'a','curve'=>ResponseCurve::linear(2,100),'min'=>0,'max'=>100]],100,function()use(&$n){return ++$n===1?['a'=>100]:null;});$this->assertSame('partial',$r['status']);$this->assertNull($r['stability']);$this->assertSame(2,$r['failed_perturbations']);$this->assertSame(0.,$r['max_share_shift_pp']);
 }
 public function test_invalid_perturbation_is_rejected_without_solver():void{config(['analytics.scenarios.perturbation_percent'=>100]);$r=(new ScenarioEngine)->sensitivity([['channel'=>'a']],100,fn()=>throw new \LogicException);$this->assertSame('not_computable',$r['status']);}
}
