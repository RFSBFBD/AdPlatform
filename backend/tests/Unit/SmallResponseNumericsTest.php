<?php
namespace Tests\Unit;
use App\Services\Analytics\Curves\ResponseCurve;
use PHPUnit\Framework\TestCase;
class SmallResponseNumericsTest extends TestCase{
 public function test_tiny_positive_inputs_do_not_collapse_to_zero_from_cancellation():void{
  foreach([ResponseCurve::log(1e20,1e-20,1,0),ResponseCurve::saturation(1e20,1e-20,1,0)]as$c){$this->assertEqualsWithDelta(1.,$c->revenueAt(1),1e-12);$this->assertEqualsWithDelta(1.,$c->marginalAt(1),1e-12);$this->assertGreaterThan(0.,$c->revenueAt(1e-5));$this->assertEqualsWithDelta(30*$c->revenueAt(1),$c->overDays(30)->revenueAt(30),1e-10);}
 }
 public function test_regular_scale_and_saturation_limit_are_preserved():void{
  $c=ResponseCurve::saturation(1000,.01,10000);$this->assertEqualsWithDelta(1000*(1-exp(-1)),$c->revenueAt(100),1e-10);$this->assertEqualsWithDelta(1000,$c->revenueAt(1e8),1e-10);$this->assertSame(0.,$c->revenueAt(0));$l=ResponseCurve::log(100,.01,1000);$this->assertEqualsWithDelta(100*log(2),$l->revenueAt(100),1e-10);
 }
}
