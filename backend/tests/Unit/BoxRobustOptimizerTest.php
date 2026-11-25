<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\BoxRobustOptimizer;
class BoxRobustOptimizerTest extends TestCase
{
    private function channels(): array { return [['channel'=>'a','display_name'=>'A','curve'=>ResponseCurve::log(100,0.1,100),'current_spend'=>50,'current_revenue'=>100,'min'=>0,'max'=>100],['channel'=>'b','display_name'=>'B','curve'=>ResponseCurve::log(90,0.1,100),'current_spend'=>50,'current_revenue'=>100,'min'=>0,'max'=>100]]; }
    public function test_robust_allocation_matches_dense_grid_worst_case(): void
    {
        $c=$this->channels();$r=(new BoxRobustOptimizer())->optimize($c,100,['a'=>0.5,'b'=>0.1]);$this->assertSame('ok',$r['status']);$this->assertEqualsWithDelta(100,$r['allocation_total'],0.01);
        $best=0;for ($x=0;$x<=100;$x+=0.05) { $best=max($best,0.5*$c[0]['curve']->revenueAt($x)+0.9*$c[1]['curve']->revenueAt(100-$x)); }
        $this->assertEqualsWithDelta($best,$r['worst_case_conversion_value'],0.03);$this->assertLessThan(50,$r['channels'][0]['spend']);$this->assertTrue($r['certificate']['within_bounds']);
    }
    public function test_zero_bound_recovers_nominal_and_bounds_never_probabilities(): void { $r=(new BoxRobustOptimizer())->optimize($this->channels(),100,['a'=>0,'b'=>0]);$this->assertSame('ok',$r['status']);$this->assertEquals($r['nominal_conversion_value'],$r['worst_case_conversion_value']);$this->assertStringContainsString('not confidence',$r['note']); }
    public function test_unknown_or_missing_uncertainty_refused(): void { $r=(new BoxRobustOptimizer())->optimize($this->channels(),100,['a'=>0.2]);$this->assertSame('not_computable',$r['status']); }
    public function test_negative_or_full_loss_bound_refused(): void { $r=(new BoxRobustOptimizer())->optimize($this->channels(),100,['a'=>-0.1,'b'=>1]);$this->assertSame('invalid_uncertainty',$r['status']); }
}
