<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\Advertising\EntityRunExplainer;
final class EntityRunExplainerTest extends TestCase{
 private function sampleResult():array{return ['status'=>'ok','objective'=>'MAXIMIZE_CONVERSIONS','response_metric'=>'conversions','currency'=>'EGP','window'=>['days'=>30],'total_budget'=>60,'scenario_total_spend'=>50,'scenario_response'=>12,'allocations'=>[['entity_key'=>'a','actual_spend'=>100,'scenario_spend'=>50,'outside_observed_support'=>true]],'sensitivity'=>['stability'=>'stable','status'=>'ok'],'limits'=>['fixture limit']];}
 public function test_actual_change_and_unsupported_confidence_are_not_inferred_from_stability():void{$e=new EntityRunExplainer;$r=$e->answer($this->sampleResult(),'how_allocation_changed');$this->assertSame(-50,$r['evidence']['entities'][0]['absolute_spend_change']);$this->assertSame(100,$r['evidence']['actual_total_spend']);$r=$e->answer($this->sampleResult(),'why_not_confident');$this->assertNull($r['evidence']['allocation_confidence']);$this->assertSame('stable',$r['evidence']['stability']);}
 public function test_objective_and_limits_are_taken_from_stored_evidence():void{$e=new EntityRunExplainer;$r=$e->answer($this->sampleResult(),'what_is_optimized');$this->assertSame('conversions',$r['evidence']['response_metric']);$this->assertSame(12,$r['evidence']['modeled_response']);$r=$e->answer($this->sampleResult(),'what_are_limits');$this->assertSame(['a'],$r['evidence']['extrapolated_entities']);$this->assertSame(['fixture limit'],$r['evidence']['limits']);$this->assertSame('not_computable',$e->answer(['status'=>'failed'],'what_are_limits')['status']);}
}
