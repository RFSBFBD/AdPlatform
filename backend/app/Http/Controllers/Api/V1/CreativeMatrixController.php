<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Ad,ConnectedAdAccount,PerformanceMetric};
use App\Services\Analytics\AdFatigueReport;
use Illuminate\Http\Request;
/** Ad proxy matrix. Never merges multiple ads by content, URL, name or creative identity. */
final class CreativeMatrixController extends Controller
{
 public function show(Request $r,AdFatigueReport $report){
  $v=$r->validate(['account_id'=>'required|integer|min:1']);$a=ConnectedAdAccount::where('user_id',$r->user()->id)->findOrFail($v['account_id']);
  $no=fn($status,$reason)=>response()->json(['success'=>true,'data'=>['status'=>$status,'reason'=>$reason,'rows'=>[]]]);
  if(!$a->currency||!in_array($a->timezone,\DateTimeZone::listIdentifiers(),true))return $no('invalid_scope','Known currency and verified reporting timezone required.');
  $ads=Ad::with('adSet.campaign')->whereHas('adSet.campaign',fn($q)=>$q->where('connected_ad_account_id',$a->id))->orderBy('id')->limit(26)->get();
  if($ads->count()>25)return $no('local_limit','Creative matrix local25-ad limit. No partial or ranked matrix shown.');
  $now=now()->setTimezone($a->timezone);$start=$now->copy()->subDays(21)->toDateString();$end=$now->copy()->subDay()->toDateString();
  $all=PerformanceMetric::where('connected_ad_account_id',$a->id)->whereIn('ad_id',$ads->pluck('id'))->whereBetween('date',[$start,$end])->orderBy('date')->limit(526)->get();
  if($all->count()>525)return $no('local_limit','Creative matrix525-ad/day row limit exceeded.');
  $groups=$all->groupBy('ad_id');$rows=[];foreach($ads as$ad){$rows[]=$report->build($ad,$a,($groups[$ad->id]??collect())->all())+['ad_id'=>$ad->id,'ad_name'=>$ad->name,'creative_type'=>$ad->creative_type];}
  return response()->json(['success'=>true,'data'=>['status'=>'ok','type'=>'OBSERVED_AD_PROXY_MATRIX','account_id'=>$a->id,'provider'=>$a->provider,'currency'=>$a->currency,'timezone'=>$a->timezone,'window'=>[$start,$end],'rows'=>$rows,'note'=>'Each ad is a proxy, not a canonical creative. No grouping by shared asset/name/URL, no audience deduplication or reach sums. Three consecutive7-day summaries retain CPM/CVR context. Engineering sustained frequency-up/CTR-down/CPA-up signal suggests checks, not a verdict about creative quality, a calibrated confidence test or causal explanation. No campaign changes.']]);
 }
}
