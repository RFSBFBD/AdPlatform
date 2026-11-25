<?php
namespace Tests\Unit;
use App\Services\Advertising\MetricOperand;
use PHPUnit\Framework\TestCase;
final class MetricOperandTest extends TestCase{
 public function test_unknown_zero_units_and_fractional_attributed_outcomes_stay_distinct():void{$this->assertNull(MetricOperand::read([],'x',false,1,true));$this->assertSame(0,MetricOperand::read(['x'=>'0'],'x',true));$this->assertSame(0.0,MetricOperand::read(['x'=>'0'],'x'));$this->assertSame(12.5,MetricOperand::read(['x'=>'12500000'],'x',false,1000000));$this->assertSame(1.5,MetricOperand::read(['x'=>'1.5'],'x'));}
 public function test_no_lossy_scalar_or_integer_conversion():void{foreach([null,true,false,[],new \stdClass,'','bad',' 1','1e3','-1','1.5','1.0000000000000001','9007199254740991.1','9007199254740992']as$v){try{MetricOperand::read(['x'=>$v],'x',true);$this->fail('Invalid count accepted');}catch(\RuntimeException){$this->assertTrue(true);}}foreach([NAN,INF,-1,'10000000000']as$v){try{MetricOperand::read(['x'=>$v],'x');$this->fail('Invalid value accepted');}catch(\RuntimeException){$this->assertTrue(true);}}}
}
