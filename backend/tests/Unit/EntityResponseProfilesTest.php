<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\Analytics\EntityResponseProfiles;
use App\Services\Analytics\Curves\ResponseCurve;
final class EntityResponseProfilesTest extends TestCase{
 public function test_period_samples_equal_curve_evaluation_and_mark_extrapolation_not_confidence():void{
  $curve=ResponseCurve::saturation(100,.01,200,50)->overDays(30);$r=(new EntityResponseProfiles)->build([['channel'=>'a','curve'=>$curve,'min'=>0,'max'=>9000]],['a'=>3000]);$this->assertSame('ok',$r['status']);$row=$r['rows'][0];$this->assertEqualsWithDelta($curve->revenueAt(3000),$row['selected_response'],1e-10);$this->assertSame(1500.,$row['support_min_spend']);$this->assertSame(6000.,$row['support_max_spend']);foreach($row['points']as$p){$this->assertEqualsWithDelta($curve->revenueAt($p['spend']),$p['modeled_response'],1e-10);$this->assertSame($p['spend']<1500||$p['spend']>6000,$p['outside_observed_support']);}$this->assertFalse(array_key_exists('confidence_interval',$row));
 }
 public function test_profile_population_limit_abstains():void{$r=(new EntityResponseProfiles)->build(array_fill(0,26,[]),[]);$this->assertSame('not_computable',$r['status']);$this->assertSame([],$r['rows']);}
 public function test_marginal_period_units_and_kink_omission():void{foreach([ResponseCurve::saturation(100,.01,200,50),ResponseCurve::power(2,.5,200,50),ResponseCurve::piecewise(2,.2,100,200,50)]as$daily){$curve=$daily->overDays(30);$r=(new EntityResponseProfiles)->build([['channel'=>'a','curve'=>$curve,'min'=>0,'max'=>6000]],['a'=>3000]);foreach($r['rows'][0]['points']as$p){if($p['spend']==0||$daily->name()==='piecewise'&&$p['spend']==3000)$this->assertNull($p['modeled_marginal_response']);else{$this->assertEqualsWithDelta($daily->marginalAt($p['spend']/30),$p['modeled_marginal_response'],1e-10);$this->assertGreaterThanOrEqual(0,$p['modeled_marginal_response']);}}}}
}
