<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\OperationsResearch\DiscreteSpendMenu;
final class DiscreteSpendMenuTest extends TestCase{
 public function test_pause_min_if_active_grid_and_cap_endpoints_are_preserved():void{$e=new DiscreteSpendMenu;$r=$e->build(25,125,110,50);$this->assertSame([0.,25.,50.,100.,110.],$r['spends']);$this->assertTrue($e->contains($r['spends'],25));$this->assertFalse($e->contains($r['spends'],75));$this->assertTrue($e->contains($r['spends'],100+1e-8));}
 public function test_zero_capacity_and_limits():void{$e=new DiscreteSpendMenu;$this->assertSame([0.],$e->build(0,0,100,10)['spends']);$this->assertSame('infeasible',$e->build(50,30,100,10)['status']);$this->assertSame('menu_limit',$e->build(0,10000,10000,.01)['status']);$this->assertSame('invalid_constraints',$e->build(0,100,100,0)['status']);}
}
