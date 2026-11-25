<?php
namespace Tests\Unit;
use App\Services\Analytics\AttributionEngine;
use PHPUnit\Framework\TestCase;
class AttributionEngineTest extends TestCase
{
    private function journey(): array { return ['conversion_id'=>'fixture-1','value'=>100,'currency'=>'EGP','converted_at'=>'2026-11-10T00:00:00Z','touches'=>[['event_id'=>'a','channel'=>'meta','at'=>'2026-11-01T00:00:00Z'],['event_id'=>'b','channel'=>'google','at'=>'2026-11-02T00:00:00Z'],['event_id'=>'c','channel'=>'meta','at'=>'2026-11-03T00:00:00Z']]]; }
    public function test_all_rule_models_conserve_total_credit_and_value(): void
    {
        foreach (['first_click','last_click','linear','time_decay','position_based'] as $m) { $r=(new AttributionEngine())->attribute([$this->journey()],$m);$this->assertEqualsWithDelta(1,array_sum(array_column($r['allocations'],'conversion_credit')),1e-12);$this->assertEqualsWithDelta(100,array_sum(array_column($r['allocations'],'value_credit')),1e-10); }
    }
    public function test_position_weights_and_repeated_channels(): void { $r=(new AttributionEngine())->attribute([$this->journey()],'position_based');$this->assertEqualsWithDelta(.8,$r['allocations']['meta']['conversion_credit'],1e-12);$this->assertEqualsWithDelta(20,$r['allocations']['google']['value_credit'],1e-12); }
    public function test_decay_half_life_ratio(): void { $r=(new AttributionEngine())->attribute([$this->journey()],'time_decay',['half_life_days'=>1]);$w=array_column($r['journeys'][0]['credits'],'weight');$this->assertEqualsWithDelta(2,$w[1]/$w[0],1e-12); }
    public function test_duplicate_conversion_is_rejected_not_double_counted(): void { $this->expectException(\InvalidArgumentException::class);$j=$this->journey();(new AttributionEngine())->attribute([$j,$j],'linear'); }
    public function test_data_driven_abstains(): void { $this->assertSame('not_computable',(new AttributionEngine())->attribute([$this->journey()],'data_driven')['status']); }
    public function test_future_touch_rejected(): void { $j=$this->journey();$j['touches'][2]['at']='2026-11-11T00:00:00Z';$this->expectException(\InvalidArgumentException::class);(new AttributionEngine())->attribute([$j],'linear'); }
    public function test_comparison_conserves_each_model_on_same_population_and_exposes_rule_disagreement():void{
        $e=new AttributionEngine;$j=$this->journey();$r=$e->compare([$j],['half_life_days'=>1]);$this->assertSame('ATTRIBUTION_MODEL_COMPARISON',$r['type']);$this->assertCount(5,$r['models']);foreach($r['models']as$m=>$v){$single=$e->attribute([$j],$m,['half_life_days'=>1]);$this->assertSame($single['allocations'],$v['allocations']);$this->assertEqualsWithDelta(100,array_sum(array_column($v['allocations'],'value_credit')),1e-10);}$this->assertSame('google',$r['rows'][0]['channel']);$this->assertEqualsWithDelta(100/3,$r['rows'][0]['value_credit_range'],1e-10);$this->assertSame(1,$r['conversion_count']);
    }
    public function test_comparison_rejects_ambiguous_order_for_every_rule_not_partial_comparison():void{$j=$this->journey();$j['touches'][1]['at']=$j['touches'][0]['at'];$this->expectException(\InvalidArgumentException::class);(new AttributionEngine)->compare([$j]);}
}
