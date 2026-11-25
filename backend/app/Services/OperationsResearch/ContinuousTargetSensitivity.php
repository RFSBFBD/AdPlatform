<?php
namespace App\Services\OperationsResearch;
use App\Services\Analytics\ScenarioEngine;
/** Numerical outer searches retain their existing non-global-certificate caveats. */
final class ContinuousTargetSensitivity
{
 public function analyze(array $channels,array $options):array{
  $objective=$options['objective'];
  if(count($channels)>3)return ['status'=>'not_computable','stability'=>null,'reason'=>'Continuous target/ratio sensitivity is locally limited to three entities to bound repeated outer searches.'];
  if(!in_array($objective,['TARGET_REVENUE','MAXIMIZE_ROAS','MINIMIZE_CPA','TARGET_CPA','TARGET_ROAS'],true))return ['status'=>'not_computable','stability'=>null,'reason'=>'Unsupported continuous sensitivity objective.'];
  $r=(new ScenarioEngine)->sensitivity($channels,(float)$options['budget'],function($chs,$budget)use($options,$objective){
   $minimum=(float)($options['minimum_total_spend']??0);
   if($objective==='TARGET_REVENUE'){$s=(new TargetValueOptimizer)->optimize($chs,(float)$options['target_reported_value'],$budget,$minimum);return $s['status']==='ok'?array_column($s['channels'],'spend','channel'):null;}
   $solver=new PortfolioRatioOptimizer;
   $s=in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?$solver->target($chs,$minimum,$budget,$objective==='TARGET_CPA'?1/(float)$options['target_ratio']:(float)$options['target_ratio']):$solver->optimize($chs,$minimum,$budget);
   return $s['status']==='ok'?array_column($s['result']['channel_allocations'],'solver_spend','channel'):null;
  });
  $r['solver']='continuous';$r['objective']=$objective;
  $r['note']='Same continuous mandatory entity bounds, cap, explicit minimum and requested target/ratio objective on every re-solve. Shares use budget CAP, not varying spend. Numerical ratio outer searches have no exact global outer error certificate; deterministic allocation stability does not strengthen that certificate. Failed or uncertified perturbations withhold stability. At most three entities/seven outer searches. Ties may move allocations. Not confidence, probability or causal performance.';
  return $r;
 }
}
