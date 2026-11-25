<?php
namespace App\Services\Analytics;
use App\Models\{Ad,AdSet,Campaign,ConnectedAdAccount};
final class EntityOwnership
{
 public function belongs(ConnectedAdAccount $account,string $level,int $id):bool{
  return match($level){
   'campaign'=>Campaign::where('id',$id)->where('connected_ad_account_id',$account->id)->where('provider',$account->provider)->exists(),
   'ad_set'=>AdSet::where('id',$id)->whereHas('campaign',fn($q)=>$q->where('connected_ad_account_id',$account->id)->where('provider',$account->provider))->exists(),
   'ad'=>Ad::where('id',$id)->whereHas('adSet.campaign',fn($q)=>$q->where('connected_ad_account_id',$account->id)->where('provider',$account->provider))->exists(),
   default=>false,
  };
 }
}
