<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{ConnectedAdAccount,PerformanceMetric};
use Illuminate\Http\Request;
/** Exact entity/day proxy, not a deduplicated canonical audience. */
final class AudienceMatrixController extends Controller
{
 public function show(Request $r){
  $v=$r->validate(['account_id'=>'required|integer|min:1','entity_level'=>'required|in:campaign,ad_set,ad','days'=>'nullable|integer|min:1|max:90']);
  $a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($v['account_id']);
  $no=fn($status,$reason)=>response()->json(['success'=>true,'data'=>['status'=>$status,'reason'=>$reason,'rows'=>[]]]);
  if(!$a->currency||!in_array($a->timezone,\DateTimeZone::listIdentifiers(),true))return $no('invalid_scope','Known currency and verified IANA timezone required.');
  $today=now()->setTimezone($a->timezone);$n=$v['days']??30;$start=$today->copy()->subDays($n)->toDateString();$end=$today->copy()->subDay()->toDateString();$level=$v['entity_level'];$column=$level.'_id';
  $q=PerformanceMetric::where('connected_ad_account_id',$a->id)->whereBetween('date',[$start,$end])->whereNotNull($column);
  if($level==='campaign')$q->whereNull('ad_set_id')->whereNull('ad_id');if($level==='ad_set')$q->whereNull('ad_id');
  $rows=$q->orderBy('date')->orderBy($column)->limit(1001)->get();
  if($rows->count()>1000)return $no('local_limit','More than1000 entity/day rows. Choose fewer days or a narrower reporting level. No partial matrix shown.');
  if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($rows))return $no('insufficient_data',$problem);
  $campaigns=\App\Models\Campaign::whereIn('id',$rows->pluck('campaign_id')->filter()->unique())->get()->keyBy('id');$sets=\App\Models\AdSet::whereIn('id',$rows->pluck('ad_set_id')->filter()->unique())->get()->keyBy('id');$ads=\App\Models\Ad::whereIn('id',$rows->pluck('ad_id')->filter()->unique())->get()->keyBy('id');
  $seen=[];$out=[];
  foreach($rows as$row){$id=(int)$row->{$column};$key=$id.'|'.$row->date->toDateString();if(isset($seen[$key]))return $no('invalid_scope','Duplicate exact entity/day rows. No ambiguous matrix shown.');$seen[$key]=true;
   $campaign=$campaigns[$row->campaign_id]??null;
   if(!$campaign||$campaign->connected_ad_account_id!==$a->id||$campaign->provider!==$a->provider||$row->provider!==$a->provider||$row->currency!==$a->currency)return $no('invalid_scope','Entity/provider/account/currency chain inconsistent.');
   // Validate supplied intermediate FK chain too, not just the chosen leaf id.
   if($level!=='campaign'){$set=$sets[$row->ad_set_id]??null;if(!$set||$set->campaign_id!==$row->campaign_id)return $no('invalid_scope','Ad-set parent linkage inconsistent.');}
   if($level==='ad'){$ad=$ads[$id]??null;if(!$ad||$ad->ad_set_id!==$row->ad_set_id)return $no('invalid_scope','Ad parent linkage inconsistent.');}
   foreach(['reach','frequency','spend','impressions','clicks','conversions']as$k)if($row->{$k}!==null&&(!is_numeric($row->{$k})||!is_finite((float)$row->{$k})||$row->{$k}<0))return $no('invalid_metrics','Nonfinite or negative source metric.');
   $numeric=fn($k)=>$row->{$k}===null?null:(float)$row->{$k};$clicks=$numeric('clicks');$imp=$numeric('impressions');$spend=$numeric('spend');$conv=$numeric('conversions');
   $out[]=['entity_id'=>$id,'date'=>$row->date->toDateString(),'reach'=>$row->reach,'frequency'=>$numeric('frequency'),'spend'=>$spend,'impressions'=>$imp,'clicks'=>$clicks,'conversions'=>$conv,'ctr'=>$clicks!==null&&$imp>0?$clicks/$imp:null,'cpa'=>$spend!==null&&$conv>0?$spend/$conv:null];
  }
  return response()->json(['success'=>true,'data'=>['status'=>'ok','type'=>'OBSERVED_ENTITY_DAY_MATRIX','account_id'=>$a->id,'provider'=>$a->provider,'entity_level'=>$level,'currency'=>$a->currency,'timezone'=>$a->timezone,'window'=>['start'=>$start,'end'=>$end,'days'=>$n],'row_count'=>count($out),'rows'=>$out,'note'=>'Exact entity/day proxies, not canonical audiences or unique people. Reach and frequency are never summed or averaged here. Missing days and null fields remain unknown, not zero. Frequency/CTR/CPA association is observational, not a saturation threshold, causal effect or comparable cross-platform attribution. Reported conversions can overlap or restate.']]);
 }
}
