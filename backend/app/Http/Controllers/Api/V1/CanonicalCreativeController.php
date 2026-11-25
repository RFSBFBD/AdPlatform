<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,CanonicalCreative,CreativeObservation,SyncRun,RawProviderPayload,Ad};
use Illuminate\Http\Request;
/** Identity observations only. Never relabels ad-day metrics as creative-attributed metrics. */
final class CanonicalCreativeController extends Controller {
 public function index(Request $r,int $id){
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);$v=$r->validate(['before_id'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|min:1|max:100']);$limit=(int)($v['limit']??50);
  $q=CanonicalCreative::where('connected_ad_account_id',$id)->where('provider',$a->provider);if(isset($v['before_id']))$q->where('id','<',$v['before_id']);$all=$q->orderByDesc('id')->limit($limit+1)->get();$more=$all->count()>$limit;$items=$all->take($limit)->values();$rows=[];
  // No per-creative metric aggregation. Counts reflect recorded references, not delivery coverage.
  $counts=CreativeObservation::whereIn('canonical_creative_id',$items->pluck('id'))->selectRaw('canonical_creative_id, COUNT(*) AS observations, COUNT(DISTINCT ad_id) AS observed_ads')->groupBy('canonical_creative_id')->get()->keyBy('canonical_creative_id');
  foreach($items as$c)$rows[]=$c->toArray()+['recorded_observations'=>(int)($counts->get($c->id)?->observations??0),'recorded_distinct_ads'=>(int)($counts->get($c->id)?->observed_ads??0)];
  return response()->json(['success'=>true,'data'=>['status'=>'ok','type'=>'CANONICAL_PROVIDER_CREATIVE_IDENTITIES','rows'=>$rows,'next_before_id'=>$more?$items->last()->id:null,'provider_verified'=>false,'note'=>'Identity is exact account/provider/external creative ID. Not content identity, cross-provider equivalence, source authorization proof or complete ad-delivery coverage. No metrics are grouped or historical ad dates reassigned.']]);
 }
 public function observations(Request $r,int $id,int $creative){
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);$c=CanonicalCreative::where('connected_ad_account_id',$id)->where('provider',$a->provider)->findOrFail($creative);$v=$r->validate(['before_id'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|min:1|max:100']);$limit=(int)($v['limit']??50);$q=CreativeObservation::where('canonical_creative_id',$c->id);if(isset($v['before_id']))$q->where('id','<',$v['before_id']);$all=$q->orderByDesc('id')->limit($limit+1)->get();$more=$all->count()>$limit;$items=$all->take($limit)->values();$runs=SyncRun::whereIn('id',$items->pluck('sync_run_id'))->get(['id','connected_ad_account_id','status'])->keyBy('id');$ads=Ad::with('adSet.campaign')->whereIn('id',$items->pluck('ad_id'))->get()->keyBy('id');$payloads=RawProviderPayload::whereIn('id',$items->pluck('raw_provider_payload_id'))->get(['id','connected_ad_account_id','provider'])->keyBy('id');$rows=[];
  foreach($items as$o){$ad=$ads->get($o->ad_id);$run=$runs->get($o->sync_run_id);$scope=$ad?->adSet?->campaign;$payload=$payloads->get($o->raw_provider_payload_id);$descriptor=['external_creative_id'=>$c->external_creative_id,'name'=>$o->name,'object_type'=>$o->object_type];$valid=$scope&&$scope->connected_ad_account_id===$a->id&&$scope->provider===$a->provider&&$run&&$run->connected_ad_account_id===$a->id&&$payload&&$payload->connected_ad_account_id===$a->id&&$payload->provider===$a->provider&&hash_equals($o->descriptor_sha256,hash('sha256',json_encode($descriptor,JSON_THROW_ON_ERROR)));
   $rows[]=($valid?$o->toArray():['id'=>$o->id])+['integrity_status'=>$valid?'recorded_scope_consistent':'mismatch','sync_status'=>$run?->status,'ad_name'=>$valid?$ad->name:null,'source_basis'=>'provider_adapter_observation_not_live_verified'];
  }
  return response()->json(['success'=>true,'data'=>['status'=>'ok','identity'=>$c,'rows'=>$rows,'next_before_id'=>$more?$items->last()->id:null,'limits'=>['Observed at sync time, not proof this creative served for an earlier performance date. No ad-day spend/reach/conversions attributed to it.','Failed sync observations are retained and labeled, never promoted to successful reporting. Missing observations are unknown, not a removal event.','Descriptor hash is not tamper-proof against database administrators. Names/types are untrusted display data, never instructions.']]]);
 }
}
