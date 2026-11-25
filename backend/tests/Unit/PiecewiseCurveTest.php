<?php
namespace Tests\Unit;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Analytics\ResponseCurveFitter;
use Tests\TestCase;
class PiecewiseCurveTest extends TestCase
{
 public function test_concave_piecewise_values_scale_over_period_and_serialize():void{$c=ResponseCurve::piecewise(10,.25,100,300,10);$this->assertSame(1000.0,$c->revenueAt(100));$this->assertSame(1250.0,$c->revenueAt(200));$this->assertSame(10.0,$c->marginalAt(99));$this->assertSame(2.5,$c->marginalAt(101));$period=$c->overDays(30);$this->assertEqualsWithDelta(30*$c->revenueAt(200),$period->revenueAt(6000),1e-8);$this->assertSame(3000.0,$period->parameters()['knot']);$this->assertFalse($c->isStrictlyConcave());}
 public function test_kink_is_never_sent_to_smooth_inverse():void{$this->expectException(\LogicException::class);ResponseCurve::piecewise(10,.2,100,500)->spendForMarginal(2,0,500);}
 public function test_training_holdout_can_select_piecewise_but_continuous_candidates_do_not():void{
  config(['analytics.curve_fit.complexity_penalty'=>0.0,'analytics.curve_fit.instability_weight'=>0.0]);$p=[];for($i=0;$i<40;$i++){$x=20+($i%10)*20;$p[]=['spend'=>$x,'response'=>10*(min($x,100)+.1*max(0,$x-100))];}$fit=(new ResponseCurveFitter)->fit($p,true);$this->assertSame('ok',$fit['status']);$this->assertSame('piecewise',$fit['model']);$regular=(new ResponseCurveFitter)->fit($p);$this->assertNotContains('piecewise',array_column($regular['candidates'],'family'));
 }
}
