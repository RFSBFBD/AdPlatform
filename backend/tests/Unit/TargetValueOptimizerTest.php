<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\TargetValueOptimizer;
class TargetValueOptimizerTest extends TestCase
{
    private function channel(float $min=0): array { return [['channel'=>'a','display_name'=>'A','curve'=>ResponseCurve::saturation(1000,0.01,1000),'current_spend'=>100,'current_revenue'=>500,'min'=>$min,'max'=>1000]]; }
    public function test_analytic_inverse_matches_target_bisection(): void { $r=(new TargetValueOptimizer())->optimize($this->channel(),500,1000);$this->assertSame('ok',$r['status']);$this->assertEqualsWithDelta(log(2)/0.01,$r['total_spend'],0.001);$this->assertGreaterThanOrEqual(500,$r['modeled_conversion_value']);$this->assertLessThan(0.001,$r['budget_gap']); }
    public function test_unreachable_target_never_promised(): void { $r=(new TargetValueOptimizer())->optimize($this->channel(),1100,1000);$this->assertSame('infeasible',$r['status']); }
    public function test_channel_minimum_is_respected_when_it_already_hits_target(): void { $r=(new TargetValueOptimizer())->optimize($this->channel(100),10,1000);$this->assertSame('ok',$r['status']);$this->assertSame(100.0,$r['total_spend']);$this->assertEquals(0,$r['budget_gap']); }
}
