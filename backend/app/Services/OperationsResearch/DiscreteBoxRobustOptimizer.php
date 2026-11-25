<?php
namespace App\Services\OperationsResearch;
use App\Services\Analytics\Curves\ScaledResponseCurve;
/** Exact independent-scale lower counterpart on the SAME finite menus, not general robust MILP. */
final class DiscreteBoxRobustOptimizer
{
 public function optimize(array $channels,float $budget,array $bounds,float $step,int $maximumActive,float $minimumSpend=0):array{
  $keys=array_column($channels,'channel');if(!$channels||array_diff($keys,array_keys($bounds))||array_diff(array_keys($bounds),$keys))return ['status'=>'not_computable','reason'=>'Explicit scale bounds for every entity, no extras, required.'];
  $worst=$channels;foreach($channels as$i=>$c){$u=$bounds[$c['channel']];if(!is_numeric($u)||!is_finite((float)$u)||$u<0||$u>=1)return ['status'=>'invalid_uncertainty','reason'=>'Every scale-error fraction must be in[0,1).'];$worst[$i]['curve']=new ScaledResponseCurve($c['curve'],1-(float)$u);}
  $r=(new DiscreteBudgetOptimizer)->optimize($worst,$budget,$step,$maximumActive,$minimumSpend);if($r['status']!=='ok')return ['status'=>$r['status'],'reason'=>$r['reason']];
  $rows=[];$nominal=$lo=$hi=0.;foreach($channels as$c){$x=$r['allocation'][$c['channel']];$v=$c['curve']->revenueAt($x);if(!is_finite($v)||$v<0)return ['status'=>'not_computable','reason'=>'Nonnegative finite menu responses required.'];$u=(float)$bounds[$c['channel']];$nominal+=$v;$lo+=$v*(1-$u);$hi+=$v*(1+$u);$rows[]=['channel'=>$c['channel'],'spend'=>$x,'scale_error_bound'=>$u,'nominal_conversion_value'=>$v,'worst_case_conversion_value'=>$v*(1-$u),'best_case_conversion_value'=>$v*(1+$u)];}
  if(abs($lo-$r['modeled_response'])>1e-6*max(1,$lo))return ['status'=>'invalid_solver_output','reason'=>'Independent worst-scale counterpart check failed.'];
  return ['status'=>'ok','type'=>'SCENARIO','solver'=>'discrete_milp','objective'=>'MAXIMIZE_WORST_CASE_REPORTED_VALUE','uncertainty_set'=>'independent_entity_output_scale_box','bounds'=>$bounds,'channels'=>$rows,'allocation_total'=>$r['total_spend'],'active_entities'=>$r['active_entities'],'nominal_conversion_value'=>$nominal,'worst_case_conversion_value'=>$lo,'best_case_conversion_value'=>$hi,'certificate'=>$r['certificate'],'note'=>'Separate finite-menu box-robust value scenario with the SAME budget step, cap, min-if-active, maximum-active and total minimum as the nominal discrete problem. For nonnegative separable outputs, every independent lower scale is the joint worst case. Explicit user assumptions, not probabilities or confidence. Exact menu optimum only, no continuous approximation bound. Does not protect against timing, attribution, shape or population error. No campaign changes.'];
 }
}
