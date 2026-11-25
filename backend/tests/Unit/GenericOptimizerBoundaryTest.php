<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
class GenericOptimizerBoundaryTest extends TestCase
{
    private function channels(): array{return [['channel'=>'a','display_name'=>'A','curve'=>ResponseCurve::linear(2,100),'current_spend'=>50,'current_revenue'=>100,'min'=>0,'max'=>100],['channel'=>'b','display_name'=>'B','curve'=>ResponseCurve::linear(2,100),'current_spend'=>50,'current_revenue'=>100,'min'=>0,'max'=>100]];}
    public function test_linear_ties_in_at_most_allocate_feasible_budget_not_empty():void{$r=(new GenericCurveOptimizer())->optimize(100,$this->channels(),['budget_mode'=>'at_most','min_marginal_roas'=>1]);$this->assertSame('ok',$r->status);$this->assertEqualsWithDelta(100,array_sum(array_column($r->channelAllocations,'solver_spend')),0.001);$this->assertEqualsWithDelta(200,$r->optimalTotalRevenue,0.001);}
    public function test_unreachable_floor_is_no_allocation():void{$r=(new GenericCurveOptimizer())->optimize(100,$this->channels(),['budget_mode'=>'at_most','min_marginal_roas'=>3]);$this->assertSame('no_allocation',$r->status);}
    public function test_floor_not_silently_ignored_in_exact_mode():void{$r=(new GenericCurveOptimizer())->optimize(100,$this->channels(),['budget_mode'=>'exact','min_marginal_roas'=>1]);$this->assertSame('constraints_required',$r->status);}
    public function test_nonconcave_constructor_is_not_admitted_to_solver():void{$this->expectException(\InvalidArgumentException::class);ResponseCurve::power(2,1.5,100);}
}
