<?php
namespace App\Services\Advertising\Sync;
use App\Models\{ConnectedAdAccount,PerformanceMetric,Campaign,AdSet,Ad,SyncRun,RawProviderPayload};
use Illuminate\Support\Facades\DB;

/** Read-only historical structural audit. Never repairs or certifies live-provider truth. */
final class CanonicalIntegrityAudit
{
 public function run(ConnectedAdAccount $account,string $start,string $end):array {
  if(DB::transactionLevel()===0)return DB::transaction(function()use($account,$start,$end){if(DB::getDriverName()==='pgsql')DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return $this->inspect($account,$start,$end);});
  return $this->inspect($account,$start,$end);
 }
 private function inspect(ConnectedAdAccount $a,string $start,string $end):array {
  $rows=PerformanceMetric::where('connected_ad_account_id',$a->id)->whereBetween('date',[$start,$end])->orderBy('id')->limit(30001)->get();
  $base=['type'=>'CANONICAL_STRUCTURAL_AUDIT','account_id'=>$a->id,'provider'=>$a->provider,'window'=>['start'=>$start,'end'=>$end],'provider_verified'=>false,'historical_repair_performed'=>false,'limits'=>['Read-only account/date-scoped audit of stored structure and recorded lineage, not source truth, authorization or full canonical-model completion.','Passing structure does not establish complete days/populations, comparable attribution, unique people, causal response or fit quality. This is not an optimization permission.','Legacy lineage remains unverified. No records are repaired, deleted or reclassified in storage.','Creative, audience and placement canonical identity coverage is not audited by this endpoint.']];
  if($rows->count()>30000)return $base+['status'=>'local_limit','reason'=>'More than30000 selected rows. Narrow the window. No partial audit or pass.','audited_rows'=>0];
  if($rows->isEmpty())return $base+['status'=>'insufficient_data','reason'=>'No stored rows in selected window. Missing is not zero.','audited_rows'=>0];
  $campaigns=Campaign::whereIn('id',$rows->pluck('campaign_id')->filter()->unique())->get(['id','connected_ad_account_id','provider','external_campaign_id'])->keyBy('id');
  $sets=AdSet::whereIn('id',$rows->pluck('ad_set_id')->filter()->unique())->get(['id','campaign_id','external_ad_set_id'])->keyBy('id');$ads=Ad::whereIn('id',$rows->pluck('ad_id')->filter()->unique())->get(['id','ad_set_id','external_ad_id'])->keyBy('id');
  $syncIds=[];$rawIds=[];foreach($rows as$r){$m=$r->metadata??[];if(is_int($m['sync_run_id']??null)&&$m['sync_run_id']>0)$syncIds[]=$m['sync_run_id'];if(is_int($m['raw_provider_payload_id']??null)&&$m['raw_provider_payload_id']>0)$rawIds[]=$m['raw_provider_payload_id'];}
  $runs=SyncRun::whereIn('id',array_unique($syncIds))->get(['id','connected_ad_account_id','status'])->keyBy('id');$raw=RawProviderPayload::whereIn('id',array_unique($rawIds))->get(['id','connected_ad_account_id','provider','endpoint'])->keyBy('id');
  $counts=[];$badRows=[];$samples=[];$seen=[];$lineage=['strict_recorded'=>0,'legacy_or_unverified'=>0,'invalid_reference'=>0];$levels=['account'=>0,'campaign'=>0,'ad_set'=>0,'ad'=>0];
  $add=function($r,string $code,bool $blocking=true)use(&$counts,&$badRows,&$samples){$counts[$code]=($counts[$code]??0)+1;if($blocking)$badRows[$r->id]=true;if(count($samples)<50)$samples[]=['metric_id'=>$r->id,'date'=>$r->date->toDateString(),'code'=>$code,'severity'=>$blocking?'blocking':'unverified'];};
  foreach($rows as$r){
   $level=$r->ad_id!==null?'ad':($r->ad_set_id!==null?'ad_set':($r->campaign_id!==null?'campaign':'account'));$levels[$level]++;
   if($r->provider!==$a->provider||!$a->currency||$r->currency!==$a->currency)$add($r,'account_provider_currency_mismatch');
   $c=$campaigns->get($r->campaign_id);$s=$sets->get($r->ad_set_id);$ad=$ads->get($r->ad_id);
   if($level!=='account'&&(!$c||$c->connected_ad_account_id!==$a->id||$c->provider!==$a->provider))$add($r,'campaign_parent_mismatch');
   if(in_array($level,['ad_set','ad'],true)&&(!$s||$s->campaign_id!==$r->campaign_id))$add($r,'ad_set_parent_mismatch');
   if($level==='ad'&&(!$ad||$ad->ad_set_id!==$r->ad_set_id))$add($r,'ad_parent_mismatch');
   $key=implode('|',[$r->date->toDateString(),$r->campaign_id,$r->ad_set_id,$r->ad_id]);if(isset($seen[$key])){$add($r,'duplicate_canonical_entity_day');$badRows[$seen[$key]]=true;}else$seen[$key]=$r->id;
   $m=$r->metadata??[];
   if(isset($m['entity_level'])&&$m['entity_level']!==$level)$add($r,'recorded_level_mismatch');
   if(($m['canonical_resolution_version']??null)!=='strict-parent-1'){$lineage['legacy_or_unverified']++;$add($r,'legacy_or_unverified_lineage',false);continue;}
   $run=$runs->get(is_int($m['sync_run_id']??null)?$m['sync_run_id']:null);$payload=$raw->get(is_int($m['raw_provider_payload_id']??null)?$m['raw_provider_payload_id']:null);
   if(!$run||$run->connected_ad_account_id!==$a->id||$run->status!=='completed'||!$payload||$payload->connected_ad_account_id!==$a->id||$payload->provider!==$a->provider||!is_string($m['provider_endpoint']??null)||$payload->endpoint!==$m['provider_endpoint']||!is_string($m['synced_at']??null)||!is_array($m['source_external_entity_ids']??null)){$lineage['invalid_reference']++;$add($r,'strict_lineage_reference_invalid');}
   else{
    try{
     $source=$m['source_external_entity_ids'];$expected=['external_campaign_id'=>$c?->external_campaign_id,'external_ad_set_id'=>$s?->external_ad_set_id,'external_ad_id'=>$ad?->external_ad_id];
     if(array_diff(array_keys($source),array_keys($expected)))throw new \RuntimeException('Unknown source identifier field.');
     foreach($source as$key=>$value)if($value!==null&&(!is_string($value)&&!is_int($value)||(string)$value!==(string)$expected[$key]))throw new \RuntimeException('Source identifier mismatch.');
     $deepest=match($level){'ad'=>'external_ad_id','ad_set'=>'external_ad_set_id','campaign'=>'external_campaign_id',default=>null};
     if($deepest!==null&&(!isset($source[$deepest])||(string)$source[$deepest]!==$expected[$deepest]))throw new \RuntimeException('Deepest source identifier missing.');
     if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$m['synced_at']))throw new \RuntimeException('Retrieval time lacks timezone.');
     $time=new \DateTimeImmutable($m['synced_at']);$timeErrors=\DateTimeImmutable::getLastErrors();
     if($timeErrors!==false&&($timeErrors['warning_count']||$timeErrors['error_count']))throw new \RuntimeException('Invalid retrieval time.');
     $lineage['strict_recorded']++;
    }catch(\Throwable){$lineage['invalid_reference']++;$add($r,'strict_source_resolution_mismatch');}
   }
  }
  ksort($counts);
  return $base+['status'=>$badRows?'integrity_issues':($lineage['legacy_or_unverified']?'structurally_consistent_unverified_lineage':'structurally_consistent_recorded_lineage'),'audited_rows'=>$rows->count(),'blocking_rows'=>count($badRows),'days_with_rows'=>$rows->pluck('date')->map(fn($d)=>$d->toDateString())->unique()->count(),'levels'=>$levels,'lineage'=>$lineage,'issue_counts'=>$counts,'issue_samples'=>$samples,'issue_samples_capped'=>array_sum($counts)>count($samples),'note'=>'Recorded lineage is not provider verification. Multiple hierarchy levels are reported separately, never added as one population.'];
 }
}
