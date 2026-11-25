<?php
namespace App\Services\OperationsResearch;
use App\Models\OptimizationRun;
/** Compare stored entity scenarios only when source population and fitted curves are identical. */
final class ArchivedScenarioComparison
{
 public function compare(OptimizationRun $a,OptimizationRun $b):array{
  foreach([$a,$b]as$r){
   if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r))return $this->no($problem);
   if($r->engine!=='entity'||$r->status!=='ok')return $this->no('Successful entity archives required.');
   if($r->integrityStatus()==='mismatch'||!$r->source_snapshot||!hash_equals($r->snapshot_sha256??'',hash('sha256',json_encode($r->source_snapshot))))return $this->no('An archive failed input/result or source integrity.');
  }
  $ra=$a->result;$rb=$b->result;
  foreach(['objective','response_metric','entity_level','currency','timezone','window','period_days']as$key)if(($ra[$key]??null)!==($rb[$key]??null))return $this->no('Different '.$key.' cannot be presented as a like-for-like archived comparison.');
  if($this->source($a)!==$this->source($b))return $this->no('Source rows, account population or fitted curves differ. No cross-dataset gain calculated.');
  $before=array_column($ra['allocations'],'scenario_spend','entity_key');$after=array_column($rb['allocations'],'scenario_spend','entity_key');ksort($before);ksort($after);
  if(array_keys($before)!==array_keys($after))return $this->no('Entity populations differ.');
  $rows=[];foreach($before as$k=>$x)$rows[]=['entity_key'=>$k,'first_spend'=>$x,'second_spend'=>$after[$k],'spend_change'=>$after[$k]-$x];
  $sameSpend=abs($ra['scenario_total_spend']-$rb['scenario_total_spend'])<=.01;
  return ['status'=>'ok','type'=>'ARCHIVED_SCENARIO_COMPARISON','first_run_id'=>$a->id,'second_run_id'=>$b->id,'currency'=>$ra['currency'],'response_metric'=>$ra['response_metric'],'objective'=>$ra['objective'],'window'=>$ra['window'],'timezone'=>$ra['timezone'],'same_total_spend'=>$sameSpend,'first'=>$this->summary($a),'second'=>$this->summary($b),'spend_change'=>$rb['scenario_total_spend']-$ra['scenario_total_spend'],'modeled_response_change'=>$rb['scenario_response']-$ra['scenario_response'],'allocations'=>$rows,'note'=>'Both archived runs use identical source rows, account population and fitted curves. Different constraints, solver or target can explain a modeled change; no dominance or optimality across different feasible sets is implied. Unequal spend is not pure reallocation. Difference is not causal lift, statistical significance or realized performance. No current metric query, refitting, AI or campaign change. Legacy unsealed result integrity remains unverified.'];
 }
 private function source(OptimizationRun $r):string{$s=$r->source_snapshot;$curves=[];foreach($s['channels']??[]as$c)$curves[$c['channel']]=$c['curve'];ksort($curves);return hash('sha256',json_encode([$s['source_rows']??null,$s['accounts']??null,$curves]));}
 private function summary(OptimizationRun $r):array{return ['run_id'=>$r->id,'created_at'=>$r->created_at,'scenario_spend'=>$r->result['scenario_total_spend'],'modeled_response'=>$r->result['scenario_response'],'solver'=>$r->result['solver']??'continuous','budget'=>$r->inputs['budget'],'minimum_total_spend'=>$r->inputs['minimum_total_spend']??null,'target_ratio'=>$r->inputs['target_ratio']??null,'target_reported_value'=>$r->inputs['target_reported_value']??null,'entity_limits'=>$r->inputs['entity_limits']??[],'budget_step'=>$r->inputs['budget_step']??null,'maximum_active'=>$r->inputs['maximum_active']??null,'result_integrity'=>$r->integrityStatus()];}
 private function no(string $reason):array{return ['status'=>'not_comparable','reason'=>$reason];}
}
