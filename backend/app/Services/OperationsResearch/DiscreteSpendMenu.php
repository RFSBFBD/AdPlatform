<?php
namespace App\Services\OperationsResearch;
/** One shared explicit spend menu for solve and fixed-scenario membership checks. */
final class DiscreteSpendMenu
{
 public function build(float $minimum,float $maximum,float $budget,float $step):array{
  if(!is_finite($minimum)||!is_finite($maximum)||!is_finite($budget)||!is_finite($step)||$minimum<0||$budget<=0||$step<=0)return ['status'=>'invalid_constraints','reason'=>'Finite nonnegative entity bounds and positive budget/step required.'];
  $hi=min($maximum,$budget);if($minimum>$hi)return ['status'=>'infeasible','reason'=>'Entity limits conflict.'];
  if(!is_finite($budget/$step)||$budget/$step>PHP_INT_MAX/4)return ['status'=>'menu_limit','reason'=>'Budget step is too small for bounded menu generation.'];
  $spends=[0.];if($minimum>0)$spends[]=$minimum;$first=max(1,(int)ceil($minimum/$step));$last=(int)floor($hi/$step);
  if($last-$first>98)return ['status'=>'menu_limit','reason'=>'Requested step creates more than101choices per entity. Increase step or narrow bounds.'];
  for($k=$first;$k<=$last;$k++)$spends[]=$k*$step;if($hi>0)$spends[]=$hi;$spends=array_values(array_unique($spends,SORT_REGULAR));sort($spends);
  if(count($spends)>101)return ['status'=>'menu_limit','reason'=>'Local101choices/entity cap.'];
  return ['status'=>'ok','spends'=>$spends,'min_if_active'=>$minimum,'max'=>$hi];
 }
 public function contains(array $spends,float $spend):bool{foreach($spends as$x)if(abs($x-$spend)<=1e-7)return true;return false;}
}
