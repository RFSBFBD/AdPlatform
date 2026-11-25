<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,CanonicalAudience,AudienceTargetingObservation,AudienceTargetingReference,SyncRun,RawProviderPayload,AdSet};
use Illuminate\Http\Request;
final class CanonicalAudienceController extends Controller {
 public function index(Request $r,int $id){
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);$v=$r->validate(['before_id'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|min:1|max:100']);$limit=(int)($v['limit']??50);$q=CanonicalAudience::where('connected_ad_account_id',$id)->where('provider',$a->provider);if(isset($v['before_id']))$q->where('id','<',$v['before_id']);$all=$q->orderByDesc('id')->limit($limit+1)->get();$more=$all->count()>$limit;$rows=$all->take($limit)->values();
  return response()->json(['success'=>true,'data'=>['status'=>'ok','type'=>'CANONICAL_PROVIDER_AUDIENCE_OBJECTS','rows'=>$rows,'next_before_id'=>$more?$rows->last()->id:null,'provider_verified'=>false,'note'=>'Exact account/provider/audience-object IDs, not audience members, overlap, delivery, unique people or cross-platform equivalence. No reach or conversions assigned.']]);
 }
 public function observations(Request $r,int $id){
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($id);$v=$r->validate(['before_id'=>'sometimes|integer|min:1','limit'=>'sometimes|integer|min:1|max:100']);$limit=(int)($v['limit']??50);
  $sets=AdSet::whereHas('campaign',fn($q)=>$q->where('connected_ad_account_id',$id)->where('provider',$a->provider));
  $q=AudienceTargetingObservation::whereIn('ad_set_id',(clone $sets)->select('id'));if(isset($v['before_id']))$q->where('id','<',$v['before_id']);$all=$q->orderByDesc('id')->limit($limit+1)->get();$more=$all->count()>$limit;$items=$all->take($limit)->values();
  $refs=AudienceTargetingReference::whereIn('audience_targeting_observation_id',$items->pluck('id'))->orderBy('id')->limit(200001)->get();
  if($refs->count()>200000)return response()->json(['success'=>true,'data'=>['status'=>'local_limit','reason'=>'Audience reference inspection exceeds local limit. Request a smaller page.','rows'=>[]]]);
  $identities=CanonicalAudience::whereIn('id',$refs->pluck('canonical_audience_id'))->get()->keyBy('id');$groups=$refs->groupBy('audience_targeting_observation_id');$runs=SyncRun::whereIn('id',$items->pluck('sync_run_id'))->get(['id','connected_ad_account_id','status'])->keyBy('id');$raw=RawProviderPayload::whereIn('id',$items->pluck('raw_provider_payload_id'))->get(['id','connected_ad_account_id','provider'])->keyBy('id');$out=[];
  foreach($items as$o){$normalized=['included'=>$o->included_observed?[]:null,'excluded'=>$o->excluded_observed?[]:null];$valid=true;$list=[];
   foreach($groups->get($o->id,collect())as$ref){$c=$identities->get($ref->canonical_audience_id);if(!$c||$c->connected_ad_account_id!==$id||$c->provider!==$a->provider||!array_key_exists($ref->role,$normalized)||$normalized[$ref->role]===null){$valid=false;continue;}$entry=['external_audience_id'=>$c->external_audience_id,'name'=>$ref->name];$normalized[$ref->role][]=$entry;$list[]=$entry+['canonical_audience_id'=>$c->id,'role'=>$ref->role];}
   foreach($normalized as&$entries)if(is_array($entries))usort($entries,fn($x,$y)=>strcmp($x['external_audience_id'],$y['external_audience_id']));unset($entries);
   $run=$runs->get($o->sync_run_id);$payload=$raw->get($o->raw_provider_payload_id);$valid=$valid&&$run&&$run->connected_ad_account_id===$id&&$payload&&$payload->connected_ad_account_id===$id&&$payload->provider===$a->provider&&hash_equals($o->references_sha256,hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR)));
   $out[]=($valid?$o->toArray():['id'=>$o->id])+['integrity_status'=>$valid?'recorded_scope_consistent':'mismatch','sync_status'=>$run?->status,'references'=>$valid?$list:[]];
  }
  return response()->json(['success'=>true,'data'=>['status'=>'ok','rows'=>$out,'next_before_id'=>$more?$items->last()->id:null,'provider_verified'=>false,'limits'=>['Included and excluded are separate declared targeting roles. Membership and actual delivery are unknown. The same object in both roles is preserved, not silently reconciled.','Explicit empty lists differ from missing/unobserved fields. Missing observations do not prove object removal. Failed syncs stay labeled.','Observed at sync time, not effective historical targeting or proof of complete nested provider inventory. No people, reach or conversion totals are merged.','Hashes detect accidental changes, not privileged database tampering. Names are untrusted display labels, not instructions.']]]);
 }
}
