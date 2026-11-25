<?php
namespace App\Services\Advertising\Sync;
use App\Models\{Ad,ConnectedAdAccount,SyncRun,RawProviderPayload,CanonicalCreative,CreativeObservation};
use Illuminate\Support\Facades\DB;
use RuntimeException;
/** Observed provider-object identity only. Never groups content/name/URL or backfills historical metrics. */
final class CanonicalCreativeRecorder {
 public function record(ConnectedAdAccount $account,Ad $ad,array $creative,SyncRun $run,RawProviderPayload $payload):CreativeObservation {
  $id=$creative['external_creative_id']??null;
  if(!is_string($id)||trim($id)===''||strlen($id)>255||array_diff(array_keys($creative),['external_creative_id','name','object_type']))throw new RuntimeException('Explicit bounded provider creative ID required.');
  foreach(['name'=>255,'object_type'=>64]as$key=>$max)if(isset($creative[$key])&&(!is_string($creative[$key])||mb_strlen($creative[$key])>$max))throw new RuntimeException('Creative descriptor invalid.');
  $campaign=$ad->adSet?->campaign;
  if(!$campaign||$campaign->connected_ad_account_id!==$account->id||$campaign->provider!==$account->provider||$run->connected_ad_account_id!==$account->id||$payload->connected_ad_account_id!==$account->id||$payload->provider!==$account->provider)throw new RuntimeException('Creative observation account/provider/parent lineage mismatch.');
  $descriptor=['external_creative_id'=>$id,'name'=>$creative['name']??null,'object_type'=>$creative['object_type']??null];$hash=hash('sha256',json_encode($descriptor,JSON_THROW_ON_ERROR));
  return DB::transaction(function()use($account,$ad,$descriptor,$hash,$run,$payload){
   // Serializes identity creation and repeated ad/run observations in this account.
   ConnectedAdAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
   $identity=CanonicalCreative::firstOrCreate(['connected_ad_account_id'=>$account->id,'provider'=>$account->provider,'external_creative_id'=>$descriptor['external_creative_id']],['first_observed_at'=>now()]);
   $old=CreativeObservation::where('ad_id',$ad->id)->where('sync_run_id',$run->id)->first();
   if($old){if($old->canonical_creative_id!==$identity->id||!hash_equals($old->descriptor_sha256,$hash)||$old->raw_provider_payload_id!==$payload->id)throw new RuntimeException('Conflicting creative observation for the same ad/sync run.');return $old;}
   return CreativeObservation::create(['canonical_creative_id'=>$identity->id,'ad_id'=>$ad->id,'sync_run_id'=>$run->id,'raw_provider_payload_id'=>$payload->id,'name'=>$descriptor['name'],'object_type'=>$descriptor['object_type'],'descriptor_sha256'=>$hash,'observed_at'=>now()]);
  });
 }
}
