<?php
namespace App\Services\Advertising\Sync;
use App\Models\{ConnectedAdAccount,AdSet,SyncRun,RawProviderPayload,CanonicalAudience,AudienceTargetingObservation,AudienceTargetingReference};
use Illuminate\Support\Facades\DB;
use RuntimeException;
/** Object references in declared targeting, never members, delivered reach or audience identity overlap. */
final class CanonicalAudienceRecorder {
 public function record(ConnectedAdAccount $a,AdSet $set,array $input,SyncRun $run,RawProviderPayload $raw):AudienceTargetingObservation {
  if(array_diff(array_keys($input),['included','excluded'])||$input===[])throw new RuntimeException('Only explicit included/excluded reference collections supported.');
  $normalized=[];
  foreach(['included','excluded']as$role){
   if(!array_key_exists($role,$input)){$normalized[$role]=null;continue;}
   if(!is_array($input[$role])||!array_is_list($input[$role])||count($input[$role])>1000)throw new RuntimeException('Audience reference list invalid or exceeds local1000 limit.');
   $seen=[];$entries=[];foreach($input[$role]as$r){if(!is_array($r))throw new RuntimeException('Audience reference must be an object.');$id=$r['external_audience_id']??null;if(!is_array($r)||array_diff(array_keys($r),['external_audience_id','name'])||!is_string($id)||trim($id)===''||strlen($id)>255||isset($seen[$id])||isset($r['name'])&&(!is_string($r['name'])||mb_strlen($r['name'])>255))throw new RuntimeException('Audience reference requires unique bounded explicit object IDs per role.');$seen[$id]=true;$entries[]=['external_audience_id'=>$id,'name'=>$r['name']??null];}
   usort($entries,fn($x,$y)=>strcmp($x['external_audience_id'],$y['external_audience_id']));$normalized[$role]=$entries;
  }
  $c=$set->campaign;if(!$c||$c->connected_ad_account_id!==$a->id||$c->provider!==$a->provider||$run->connected_ad_account_id!==$a->id||$raw->connected_ad_account_id!==$a->id||$raw->provider!==$a->provider)throw new RuntimeException('Audience observation account/provider/parent scope mismatch.');
  $hash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
  return DB::transaction(function()use($a,$set,$run,$raw,$normalized,$hash){
   ConnectedAdAccount::whereKey($a->id)->lockForUpdate()->firstOrFail();$old=AudienceTargetingObservation::where('ad_set_id',$set->id)->where('sync_run_id',$run->id)->first();
   if($old){if(!hash_equals($old->references_sha256,$hash)||$old->raw_provider_payload_id!==$raw->id)throw new RuntimeException('Conflicting audience reference evidence for same set/run.');return $old;}
   $o=AudienceTargetingObservation::create(['ad_set_id'=>$set->id,'sync_run_id'=>$run->id,'raw_provider_payload_id'=>$raw->id,'included_observed'=>$normalized['included']!==null,'excluded_observed'=>$normalized['excluded']!==null,'references_sha256'=>$hash,'observed_at'=>now()]);
   foreach($normalized as$role=>$entries)foreach($entries??[]as$r){$identity=CanonicalAudience::firstOrCreate(['connected_ad_account_id'=>$a->id,'provider'=>$a->provider,'external_audience_id'=>$r['external_audience_id']],['first_observed_at'=>now()]);AudienceTargetingReference::create(['audience_targeting_observation_id'=>$o->id,'canonical_audience_id'=>$identity->id,'role'=>$role,'name'=>$r['name']]);}
   return $o;
  });
 }
}
