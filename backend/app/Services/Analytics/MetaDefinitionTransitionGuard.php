<?php
namespace App\Services\Analytics;
/** Import-transition boundaries, not a general metric-meaning or source-verification pass. */
final class MetaDefinitionTransitionGuard {
 public function problem(iterable $rows,bool $outcomes=true,bool $semantics=true):?string{
  $definitions=[];$new=false;$unknown=false;
  foreach($rows as$row){if($row->provider==='snapchat')return 'Snapchat canonical history is unverified: the implemented bare account stats source supports spend only, not delivery or purchases. Stored rows are unchanged; inspect source spend separately. No canonical analytical or archive decision is supported.';if($row->provider!=='meta'){
   if(($row->metadata['normalization_version']??null)!==\App\Services\Advertising\MetricOperand::VERSION)return 'Legacy or missing provider normalization evidence cannot support this analysis. No historical values were repaired.';
   if(in_array($row->provider,['google','tiktok','snapchat'],true)&&($row->metadata['account_metadata_source']??null)!==\App\Services\Advertising\AccountMetadataEvidence::VERSION)return 'Account currency/timezone discovery evidence is unverified for this provider history.';
   continue;
  }$d=$row->metadata['metric_definition']??null;if(!$outcomes){if(($row->metadata['normalization_version']??null)!==\App\Services\Advertising\MetricOperand::VERSION||($d['normalization']??null)!=='strict-meta-operands-1'||($d['account_metadata_source']??null)!=='meta-discovery-explicit-1')return 'Typed outcomes do not validate legacy spend or account metadata operands.';continue;}if(is_array($d)&&($d['version']??null)==='meta-pixel-purchase-1'&&($d['normalization']??null)==='strict-meta-operands-1'&&($d['account_metadata_source']??null)==='meta-discovery-explicit-1'&&($d['conversion']??null)==='offsite_conversion.fb_pixel_purchase'&&($d['value']??null)==='offsite_conversion.fb_pixel_purchase'){$new=true;$copy=$d;ksort($copy);$definitions[json_encode($copy,JSON_THROW_ON_ERROR)]=true;}else{$unknown=true;}}
  if($unknown||($new&&count($definitions)!==1))return 'Legacy, missing or differing Meta outcome definitions cannot support this analysis. Narrow the history or verify and reimport it; no rows were repaired.';
  return $outcomes&&$semantics?(new OutcomeSemanticGuard)->problem($rows):null;
 }
 public function userProblem(int $userId,string $start,string $end,?string $provider=null,?int $accountId=null,bool $separateAccounts=false,bool $semantics=true):?string{
  $q=\App\Models\PerformanceMetric::whereIn('connected_ad_account_id',\App\Models\ConnectedAdAccount::where('user_id',$userId)->select('id'))->when($provider,fn($q)=>$q->where('provider',$provider))->whereBetween('date',[$start,$end]);
  if($accountId)$q->where('connected_ad_account_id',$accountId);$rows=$q->limit(30001)->get(['provider','metadata','connected_ad_account_id']);
  if($rows->count()>30000)return 'Provider definition validation exceeds local30000-row cap.';
  if($separateAccounts){foreach($rows->groupBy('connected_ad_account_id')as$group)if($p=$this->problem($group,true,$semantics))return $p;return null;}
  return $this->problem($rows,true,$semantics);
 }
 public function archiveProblem(\App\Models\OptimizationRun $run):?string{
  $source=$run->source_snapshot;
  if(!$source||!isset($source['source_rows']))return 'Archived metric definitions cannot be validated because source rows are missing. Stored output is not a verified decision basis.';
  if($run->engine===\App\Services\OperationsResearch\OptimizationOrchestrator::ENGINE){
   if($run->integrityStatus()!=='matches'||!hash_equals($run->snapshot_sha256??'',hash('sha256',json_encode($source))))return 'Portfolio archive input/result or source checksum failed.';
   $pf=$source['objective_preflight']??null;
   if(!is_array($pf)||($pf['version']??null)!==\App\Services\OperationsResearch\ObjectiveEstimandPreflight::VERSION||$pf!==($run->result['objective_preflight']??null)||($pf['selection']['objective']??null)!==($run->inputs['objective']??null)||($pf['selection']['outcome_basis']??null)!==($run->inputs['outcome_basis']??null))return 'Explicit objective/basis preflight is missing or differs from archived inputs/result. Legacy objectives are not inferred.';
   if($run->status!=='ok')return null; // A verified refusal contains no allocation; keep its descriptive evidence readable.
  }
  $outcomes=!in_array($run->inputs['objective']??null,['MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS'],true);
  $rows=[];foreach($source['source_rows']as$row){$meta=$row['metadata']??null;if(is_string($meta))$meta=json_decode($meta,true);$rows[]=(object)['provider'=>$row['provider']??null,'connected_ad_account_id'=>$row['connected_ad_account_id']??null,'metadata'=>is_array($meta)?$meta:[]];}
  return $this->problem($rows,$outcomes);
 }

}
