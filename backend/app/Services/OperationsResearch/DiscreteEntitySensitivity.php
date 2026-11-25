<?php
namespace App\Services\OperationsResearch;
use App\Services\Analytics\ScenarioEngine;
/** Bounded engineering perturbation. Always retains the requested discrete objective. */
final class DiscreteEntitySensitivity
{
 public function analyze(array $channels,array $options):array{
  if(count($channels)>3)return ['status'=>'not_computable','stability'=>null,'reason'=>'Local discrete sensitivity cap is three entities (at most seven certified MILP solves). No continuous substitute.'];
  $objective=$options['objective'];
  if(!in_array($objective,['MAXIMIZE_REVENUE','MAXIMIZE_CONVERSIONS','MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS','TARGET_REVENUE','TARGET_CPA','TARGET_ROAS'],true))return ['status'=>'not_computable','stability'=>null,'reason'=>'Unsupported discrete sensitivity objective.'];
  $r=(new ScenarioEngine)->sensitivity($channels,(float)$options['budget'],function($chs,$budget)use($options,$objective){
   $s=(new DiscreteBudgetOptimizer)->optimize($chs,$budget,(float)$options['budget_step'],(int)$options['maximum_active'],(float)($options['minimum_total_spend']??0),$objective==='TARGET_REVENUE'?(float)$options['target_reported_value']:null,in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?($objective==='TARGET_CPA'?1/(float)$options['target_ratio']:(float)$options['target_ratio']):null);
   return $s['status']==='ok'?$s['allocation']:null;
  });
  $r['solver']='discrete_milp';$r['objective']=$objective;
  $r['note']='Same finite menus, requested objective, target, min-if-active, total minimum, maximum-active and cap for each re-solve. Shifts are percentage points of the budget CAP, not of possibly varying spent total. One entity response scale changes at a time. Failed or uncertified runs withhold stability. Tied optima can move allocations without material objective change. Not a probability, causal forecast or continuous optimum. At most seven solver calls, each capped at15seconds.';
  return $r;
 }
}
