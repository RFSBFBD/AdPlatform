<?php
namespace Tests\Unit;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Analytics\ResponseCurveFitter;
use Tests\TestCase;
class QuadraticSplineTest extends TestCase{
 public function test_value_derivative_concavity_period_scaling_and_tail():void{
  $c=ResponseCurve::quadraticSpline(10,.25,100,300,10);$this->assertSame(625.,$c->revenueAt(100));$this->assertSame(875.,$c->revenueAt(200));$this->assertSame(10.,$c->marginalAt(0));$this->assertSame(2.5,$c->marginalAt(100));$this->assertEqualsWithDelta($c->marginalAt(100-1e-7),$c->marginalAt(100+1e-7),1e-7);$last=INF;for($x=0;$x<=300;$x+=5){$this->assertLessThanOrEqual($last,$c->marginalAt($x));$last=$c->marginalAt($x);}$p=$c->overDays(30);$this->assertEqualsWithDelta(30*$c->revenueAt(150),$p->revenueAt(4500),1e-8);$this->assertSame(3000.,$p->parameters()['knot']);
 }
 public function test_discrete_only_family_can_win_chronological_validation():void{
  config(['analytics.curve_fit.complexity_penalty'=>0.,'analytics.curve_fit.instability_weight'=>0.]);$c=ResponseCurve::quadraticSpline(10,.25,100,300);$p=[];for($i=0;$i<40;$i++){$x=20+($i%10)*20;$p[]=['spend'=>$x,'response'=>$c->revenueAt($x)];}$fit=(new ResponseCurveFitter)->fit($p,true);$this->assertSame('quadratic_spline',$fit['model']);$regular=(new ResponseCurveFitter)->fit($p);$this->assertNotContains('quadratic_spline',array_column($regular['candidates'],'family'));
 }
 public function test_unapproved_inverse_is_refused_even_for_zero_lambda():void{$this->expectException(\LogicException::class);ResponseCurve::quadraticSpline(10,.25,100,300)->spendForMarginal(0,0,300);}
 public function test_nonconcave_slope_ratio_is_refused():void{$this->expectException(\InvalidArgumentException::class);ResponseCurve::quadraticSpline(10,1.1,100,300);}
}
