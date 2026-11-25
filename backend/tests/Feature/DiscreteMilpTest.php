<?php

namespace Tests\Feature;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\DiscreteBudgetOptimizer;
use Tests\TestCase;

class DiscreteMilpTest extends TestCase
{
    private function ch(string $id, float $scale, float $min = 0): array
    {
        return ['channel' => $id, 'curve' => ResponseCurve::saturation($scale, .01, 1000), 'min' => $min, 'max' => 200];
    }

    public function test_missing_dependency_is_explicit_no_fallback(): void
    {
        config(['solver.python' => '']);
        $this->assertSame('solver_unavailable', (new DiscreteBudgetOptimizer)->optimize([$this->ch('a', 100)], 200, 20, 1)['status']);
    }

    public function test_highs_menus_match_exhaustive_search_with_activation_and_minimums(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        $channels = [$this->ch('a', 100, 50), $this->ch('b', 130, 80), $this->ch('c', 70, 20)];
        $r = (new DiscreteBudgetOptimizer)->optimize($channels, 250, 25, 2, 100);
        if ($r['status'] === 'unavailable' || $r['status'] === 'solver_unavailable') {
            $this->markTestSkipped('Optional SciPy runtime not installed');
        }$this->assertSame('ok', $r['status'], json_encode($r));
        $best = -INF;
        foreach ($r['menus'][0]['choices'] as $a) {
            foreach ($r['menus'][1]['choices'] as $b) {
                foreach ($r['menus'][2]['choices'] as $c) {
                    $s = $a['spend'] + $b['spend'] + $c['spend'];
                    $active = ($a['spend'] > 0) + ($b['spend'] > 0) + ($c['spend'] > 0);
                    if ($s <= 250 && $s >= 100 && $active <= 2) {
                        $best = max($best, $a['response'] + $b['response'] + $c['response']);
                    }
                }
            }
        }
        $this->assertEqualsWithDelta($best, $r['modeled_response'], 1e-6);
        $this->assertTrue($r['certificate']['independent_feasibility_checked']);
        $this->assertSame('finite sampled menus only', $r['certificate']['global_optimum_scope']);
        $this->assertNull($r['certificate']['continuous_error_bound']);
    }

    public function test_menu_limits_and_infeasibility_are_not_silent_partial_allocations(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        $this->assertSame('menu_limit', (new DiscreteBudgetOptimizer)->optimize([$this->ch('a', 100)], 200, .01, 1)['status']);
        $r = (new DiscreteBudgetOptimizer)->optimize([$this->ch('a', 100, 150), $this->ch('b', 100, 150)], 250, 50, 1, 220);
        if ($r['status'] === 'unavailable' || $r['status'] === 'solver_unavailable') {
            $this->markTestSkipped('Optional SciPy runtime not installed');
        }$this->assertSame('not_solved',$r['status']);
    }
    public function test_minimum_spend_target_menu_matches_exhaustive_search():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);$channels=[$this->ch('a',100,50),$this->ch('b',130,80),$this->ch('c',70,20)];$solver=new DiscreteBudgetOptimizer;$r=$solver->optimize($channels,250,25,2,50,100);
        if(in_array($r['status'],['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$this->assertSame('ok',$r['status'],json_encode($r));$best=INF;
        foreach($r['menus'][0]['choices']as$a)foreach($r['menus'][1]['choices']as$b)foreach($r['menus'][2]['choices']as$c){$sp=$a['spend']+$b['spend']+$c['spend'];$v=$a['response']+$b['response']+$c['response'];$active=($a['spend']>0)+($b['spend']>0)+($c['spend']>0);if($sp>=50&&$sp<=250&&$v>=100&&$active<=2)$best=min($best,$sp);}
        $this->assertEqualsWithDelta($best,$r['total_spend'],1e-6);$this->assertGreaterThanOrEqual(100,$r['modeled_response']);$this->assertEqualsWithDelta($best,$r['certificate']['dual_spend_bound'],1e-6);$this->assertSame('minimize_spend_for_target_response',$r['certificate']['objective']);$this->assertSame('not_solved',$solver->optimize($channels,250,25,2,50,999999)['status']);$atMinimum=$solver->optimize($channels,250,25,2,200,10);$this->assertSame('ok',$atMinimum['status']);$this->assertEqualsWithDelta(200,$atMinimum['total_spend'],1e-6);$this->assertSame('invalid_constraints',$solver->optimize($channels,250,25,2,0,-1)['status']);
    }

    public function test_ratio_constrained_response_maximum_matches_exhaustive_menu_search():void{
        config(['solver.python'=>\Tests\SolverRuntime::python($this)]);$chs=[$this->ch('a',100,50),$this->ch('b',130,80),$this->ch('c',70,20)];$solver=new DiscreteBudgetOptimizer;$r=$solver->optimize($chs,250,25,2,50,null,.7);if(in_array($r['status'],['unavailable','solver_unavailable'],true))$this->markTestSkipped('Optional SciPy runtime not installed');$this->assertSame('ok',$r['status'],json_encode($r));$best=-INF;
        foreach($r['menus'][0]['choices']as$a)foreach($r['menus'][1]['choices']as$b)foreach($r['menus'][2]['choices']as$c){$sp=$a['spend']+$b['spend']+$c['spend'];$v=$a['response']+$b['response']+$c['response'];$active=($a['spend']>0)+($b['spend']>0)+($c['spend']>0);if($sp>=50&&$sp<=250&&$v>=.7*$sp&&$active<=2)$best=max($best,$v);}
        $this->assertEqualsWithDelta($best,$r['modeled_response'],1e-6);$this->assertGreaterThanOrEqual(.7,$r['modeled_response']/$r['total_spend']);$this->assertSame('not_solved',$solver->optimize($chs,250,25,2,50,null,999)['status']);$this->assertSame('invalid_constraints',$solver->optimize($chs,250,25,2,0,null,.7)['status']);
    }

}
