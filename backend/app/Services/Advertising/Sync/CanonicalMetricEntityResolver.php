<?php
namespace App\Services\Advertising\Sync;
use App\Models\{Ad,AdSet,Campaign,ConnectedAdAccount};
use RuntimeException;

/** Strict metric-to-canonical hierarchy mapping. Unknown never becomes account-level. */
final class CanonicalMetricEntityResolver
{
 public function resolve(ConnectedAdAccount $account,array $metric):array
 {
  foreach(['provider'=>$account->provider,'currency'=>$account->currency,'external_account_id'=>$account->external_account_id]as$key=>$expected)if(array_key_exists($key,$metric)&&$metric[$key]!==null&&(string)$metric[$key]!==$expected)throw new RuntimeException('Supplied metric account/provider/currency disagrees with canonical account.');
  $ids=[];
  foreach(['campaign','ad_set','ad']as$level){$key='external_'.$level.'_id';$value=$metric[$key]??null;
   if($value!==null&&(!is_string($value)&&!is_int($value)||trim((string)$value)===''))throw new RuntimeException('Invalid supplied external entity identifier.');
   $ids[$level]=$value===null?null:(string)$value;
  }
  $campaign=null;$set=null;$ad=null;
  if($ids['campaign']!==null){
   $matches=Campaign::where('connected_ad_account_id',$account->id)->where('provider',$account->provider)->where('external_campaign_id',$ids['campaign'])->limit(2)->get();
   if($matches->count()!==1)throw new RuntimeException('Supplied campaign cannot resolve uniquely inside account/provider scope.');$campaign=$matches[0];
  }
  if($ids['ad_set']!==null){
   $q=AdSet::where('external_ad_set_id',$ids['ad_set'])->whereHas('campaign',fn($q)=>$q->where('connected_ad_account_id',$account->id)->where('provider',$account->provider));
   if($campaign)$q->where('campaign_id',$campaign->id);
   $matches=$q->limit(2)->get();if($matches->count()!==1)throw new RuntimeException('Supplied ad-set cannot resolve uniquely within the stated parent/account/provider scope.');$set=$matches[0];
   $campaign=$campaign??$set->campaign;
  }
  if($ids['ad']!==null){
   $q=Ad::where('external_ad_id',$ids['ad'])->whereHas('adSet.campaign',fn($q)=>$q->where('connected_ad_account_id',$account->id)->where('provider',$account->provider));
   if($set)$q->where('ad_set_id',$set->id);elseif($campaign)$q->whereHas('adSet',fn($q)=>$q->where('campaign_id',$campaign->id));
   $matches=$q->limit(2)->get();if($matches->count()!==1)throw new RuntimeException('Supplied ad cannot resolve uniquely within the stated parent/account/provider scope.');$ad=$matches[0];$set=$set??$ad->adSet;$campaign=$campaign??$set->campaign;
  }
  if($set&&(!$campaign||$set->campaign_id!==$campaign->id)||$ad&&(!$set||$ad->ad_set_id!==$set->id))throw new RuntimeException('Metric hierarchy parent chain conflicts.');
  $level=$ad?'ad':($set?'ad_set':($campaign?'campaign':'account'));
  if(isset($metric['entity_level'])&&$metric['entity_level']!==$level)throw new RuntimeException('Declared metric level disagrees with resolved canonical hierarchy.');
  return ['campaign_id'=>$campaign?->id,'ad_set_id'=>$set?->id,'ad_id'=>$ad?->id,'entity_level'=>$level];
 }
}
