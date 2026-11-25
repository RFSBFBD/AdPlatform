<?php
namespace App\Services\OperationsResearch;
/** Validate a runtime claim independently from the choice feasibility checks. Not an external proof. */
final class DiscreteSolverCertificate
{
 public function accepts(array $r,float $spend,float $response,bool $minimumSpendObjective):bool{
  if(($r['solver']??null)!=='scipy.optimize.milp/HiGHS'||($r['model_version']??null)!=='discrete-menu-3'||!is_string($r['scipy_version']??null)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[a-zA-Z0-9.+-]*)$/',$r['scipy_version'])||($r['solver_status']??null)!==0)return false;
  foreach(['mip_gap','objective_response']as$key)if(!isset($r[$key])||!is_int($r[$key])&&!is_float($r[$key])||!is_finite((float)$r[$key]))return false;
  if($r['mip_gap']<0||$r['mip_gap']>1e-8||abs($response-$r['objective_response'])>1e-5*max(1,$response))return false;
  $key=$minimumSpendObjective?'dual_spend_bound':'dual_response_bound';if(!isset($r[$key])||!is_int($r[$key])&&!is_float($r[$key])||!is_finite((float)$r[$key]))return false;
  $objective=$minimumSpendObjective?$spend:$response;$tolerance=1e-5*max(1,$objective);if(abs($objective-$r[$key])>$tolerance)return false;
  if($minimumSpendObjective&&(!isset($r['objective_spend'])||!is_numeric($r['objective_spend'])||!is_finite((float)$r['objective_spend'])||abs($spend-$r['objective_spend'])>1e-6))return false;
  return true;
 }
}
