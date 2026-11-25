<?php
namespace App\Services\OperationsResearch;
/** Same curves for no-change, equal-spend baseline and optional custom scenario. No refit or forecast. */
final class EntityScenarioComparison
{
 public function build(array $channels,array $options,array $result):array{
  $actual=array_column($channels,'current_spend','channel');$spent=array_sum($actual);$scenarioSpend=(float)$result['scenario_total_spend'];$scaled=[];foreach($actual as$k=>$x)$scaled[$k]=$spent>0?$x*$scenarioSpend/$spent:0.;
  $out=['type'=>'SCENARIO','no_change'=>$this->evaluate($channels,$actual,$options),'same_scenario_spend_baseline'=>$this->evaluate($channels,$scaled,$options),'note'=>'No-change preserves observed budgets but evaluates fitted curves, not actual reported response. Same-spend baseline rescales observed mix to scenario spend. Baselines may violate proposed constraints; flags remain visible. No causal lift or calibrated confidence.'];
  $out['modeled_gain_vs_same_spend_baseline']=$result['scenario_response']-$out['same_scenario_spend_baseline']['modeled_response'];
  if(isset($options['custom_allocation'])){
   $custom=$options['custom_allocation'];if(array_diff(array_keys($actual),array_keys($custom))||array_diff(array_keys($custom),array_keys($actual))){$out['custom']=['status'=>'invalid_scope','reason'=>'Exactly one allocation for every selected entity is required. No silent fill or extra entity.'];}
   else{$out['custom']=$this->evaluate($channels,$custom,$options);$out['custom']['status']=$out['custom']['feasible_under_requested_constraints']?'ok':'infeasible';}
  }return $out;
 }
 private function evaluate(array $channels,array $allocation,array $o):array{
  $value=$spend=0.;$active=0;$violations=[];$rows=[];$discrete=($o['solver']??'continuous')==='discrete_milp';
  foreach($channels as$c){$x=(float)$allocation[$c['channel']];if(!is_finite($x)||$x<0)return ['status'=>'invalid_data','feasible_under_requested_constraints'=>false,'reason'=>'All spends must be finite nonnegative values.'];$y=$c['curve']->revenueAt($x);$spend+=$x;$value+=$y;$active+=(int)($x>0);if($x>$c['max']+1e-7||$x<$c['min']-1e-7&&(!$discrete||$x>0))$violations[]='entity_bounds:'.$c['channel'];if($discrete){$menu=(new DiscreteSpendMenu)->build((float)$c['min'],(float)$c['max'],(float)$o['budget'],(float)$o['budget_step']);if($menu['status']!=='ok')$violations[]='menu_not_computable:'.$c['channel'];elseif(!(new DiscreteSpendMenu)->contains($menu['spends'],$x))$violations[]='off_menu:'.$c['channel'];}$rows[]=['entity_key'=>$c['channel'],'spend'=>$x,'modeled_response'=>$y,'outside_observed_support'=>$x<$c['curve']->supportMinSpend()||$x>$c['curve']->supportMaxSpend()];}
  if($spend>(float)$o['budget']+1e-7)$violations[]='budget_cap';if($spend<(float)($o['minimum_total_spend']??0)-1e-7)$violations[]='minimum_total_spend';if($discrete&&$active>(int)$o['maximum_active'])$violations[]='maximum_active';$ratio=$spend>0?$value/$spend:null;$objective=$o['objective'];if(in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)&&($ratio===null||$ratio<($objective==='TARGET_CPA'?1/(float)$o['target_ratio']:(float)$o['target_ratio'])-1e-7))$violations[]='target_ratio';
  if($objective==='TARGET_REVENUE'&&$value<(float)$o['target_reported_value']-1e-7)$violations[]='target_reported_value';
  return ['status'=>'evaluated','type'=>'SCENARIO','total_spend'=>$spend,'modeled_response'=>$value,'feasible_under_requested_constraints'=>$violations===[],'constraint_violations'=>$violations,'menu_membership_checked'=>$discrete,'allocation'=>$rows];
 }
}
