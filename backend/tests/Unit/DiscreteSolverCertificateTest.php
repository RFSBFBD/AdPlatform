<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\OperationsResearch\DiscreteSolverCertificate;
final class DiscreteSolverCertificateTest extends TestCase{
 private function sample():array{return ['solver'=>'scipy.optimize.milp/HiGHS','model_version'=>'discrete-menu-3','scipy_version'=>'1.14.1','solver_status'=>0,'mip_gap'=>0.,'objective_response'=>100.,'dual_response_bound'=>100.];}
 public function test_response_maximum_requires_finite_matching_dual_and_runtime_contract():void{$v=new DiscreteSolverCertificate;$s=$this->sample();$this->assertTrue($v->accepts($s,50,100,false));foreach(['solver'=>'unknown','model_version'=>'new_unreviewed_model','scipy_version'=>'unknown','solver_status'=>1,'mip_gap'=>-1,'objective_response'=>NAN,'dual_response_bound'=>INF]as$key=>$value){$bad=$s;$bad[$key]=$value;$this->assertFalse($v->accepts($bad,50,100,false),$key);}foreach([null,50.,150.,'100']as$bound){$bad=$s;$bad['dual_response_bound']=$bound;$this->assertFalse($v->accepts($bad,50,100,false));}}
 public function test_target_minimum_requires_spend_bound_not_response_bound():void{$v=new DiscreteSolverCertificate;$s=$this->sample()+['objective_spend'=>50.,'dual_spend_bound'=>50.];$this->assertTrue($v->accepts($s,50,100,true));$s['dual_spend_bound']=49.;$this->assertFalse($v->accepts($s,50,100,true));unset($s['dual_spend_bound']);$this->assertFalse($v->accepts($s,50,100,true));}
}
