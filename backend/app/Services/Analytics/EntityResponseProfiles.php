<?php
namespace App\Services\Analytics;
/** Descriptive fitted-period response samples, never a saturation confidence estimate. */
final class EntityResponseProfiles
{
 public function build(array $channels,array $allocation):array{
  if(count($channels)>25)return ['status'=>'not_computable','reason'=>'Response-profile chart locally limited to25entities.','rows'=>[]];
  $rows=[];
  foreach($channels as$c){$curve=$c['curve'];$max=(float)$c['max'];$supportMin=$curve->supportMinSpend();$supportMax=$curve->supportMaxSpend();$chosen=$allocation[$c['channel']]??null;
   if(!is_finite($max)||$max<=0)return ['status'=>'not_computable','reason'=>'Finite positive chart range required.','rows'=>[]];
   $xs=[0.,$max,$supportMin,$supportMax,(float)$c['min']];$parameters=$curve->parameters();if(isset($parameters['knot']))$xs[]=(float)$parameters['knot'];if($chosen!==null)$xs[]=(float)$chosen;for($i=1;$i<20;$i++)$xs[]=$max*$i/20;
   $xs=array_values(array_unique(array_filter($xs,fn($x)=>is_finite($x)&&$x>=0&&$x<=$max),SORT_REGULAR));sort($xs);$points=[];
   foreach($xs as$x){$v=$curve->revenueAt($x);if(!is_finite($v)||$v<0)return ['status'=>'not_computable','reason'=>'Nonfinite or negative fitted response.','rows'=>[]];$m=$x>0?$curve->marginalAt($x):null;$isKink=$curve->name()==='piecewise'&&isset($parameters['knot'])&&abs($x-$parameters['knot'])<=1e-9;$points[]=['spend'=>$x,'modeled_response'=>$v,'modeled_marginal_response'=>$isKink?null:$m,'marginal_note'=>$isKink?'No single derivative at hinge knot.':($x==0?'Origin derivative omitted by chart convention (power-family marginal can be singular).':null),'outside_observed_support'=>$x<$supportMin||$x>$supportMax];}
   $rows[]=['entity_key'=>$c['channel'],'family'=>$curve->name(),'support_min_spend'=>$supportMin,'support_max_spend'=>$supportMax,'minimum_spend'=>$c['min'],'maximum_spend'=>$max,'selected_spend'=>$chosen,'selected_response'=>$chosen===null?null:$curve->revenueAt($chosen),'points'=>$points];
  }
  return ['status'=>'ok','type'=>'SCENARIO','period_days'=>30,'rows'=>$rows,'note'=>'Fitted 30-day response under equal daily spend, not actual observations or causal lift. Points outside scaled observed daily-spend support are extrapolation. Zero is the explicit pause-response convention, not observed evidence. Marginal response is fitted output per extra currency unit of period budget, not causal incrementality. No single derivative at a hinge knot; origin derivative omitted. No saturation threshold, confidence band or prediction interval is inferred. Sampled lines are visual interpolation, not extra measurements. Discrete solver may choose sampled menus different from chart points.'];
 }
}
