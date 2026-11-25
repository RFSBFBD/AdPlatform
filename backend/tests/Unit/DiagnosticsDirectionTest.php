<?php
namespace Tests\Unit;
use App\Services\Analytics\DiagnosticsEngine;
use Tests\TestCase;
class DiagnosticsDirectionTest extends TestCase
{
 private function runDiagnostic(array $c,array $b):array{return(new DiagnosticsEngine)->diagnose($c,$b,['days_current'=>14,'days_baseline'=>14]);}
 private function base():array{return['spend'=>1000,'impressions'=>100000,'clicks'=>1000,'conversions'=>100,'conversion_value'=>5000];}
 public function test_uniform_scale_down_is_neutral_volume_change_not_deterioration():void{$b=$this->base();$c=array_map(fn($v)=>$v*.5,$b);$r=$this->runDiagnostic($c,$b);$this->assertSame('changing',$r['stages']['delivery']);$this->assertSame('changing',$r['stages']['traffic']);$this->assertSame('changing',$r['stages']['conversion']);$this->assertSame('deteriorating',$r['stages']['revenue']);}
 public function test_lower_costs_are_improvements_even_though_raw_signals_are_down():void{$b=$this->base();$c=$b;$c['spend']=500;$r=$this->runDiagnostic($c,$b);$this->assertSame('improving',$r['stages']['delivery']);$this->assertSame('improving',$r['stages']['traffic']);$this->assertSame('improving',$r['stages']['conversion']);$this->assertSame('improving',$r['stages']['revenue']);$this->assertSame('down',$r['signals']['cpc']['state']);}
 public function test_disagreeing_efficiency_signals_stay_mixed():void{$b=$this->base();$c=$b;$c['spend']=2000;$c['clicks']=1500;$r=$this->runDiagnostic($c,$b);$this->assertSame('mixed',$r['stages']['traffic']);}
 public function test_missing_efficiencies_are_not_called_stable():void{$b=$this->base();unset($b['conversion_value']);$r=$this->runDiagnostic($b,$b);$this->assertSame('unknown',$r['stages']['revenue']);$b=$this->base();unset($b['spend']);$r=$this->runDiagnostic($b,$b);$this->assertSame('partial',$r['stages']['traffic']);}
}
