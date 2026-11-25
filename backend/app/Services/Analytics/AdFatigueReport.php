<?php
namespace App\Services\Analytics;
use App\Models\Ad;
/** Shared single-ad/matrix preparation. Caller supplies all selected rows, no hidden hierarchy aggregation. */
final class AdFatigueReport
{
 public function build(Ad $ad,$account,array $rows):array{
  if(!$account->currency||!in_array($account->timezone,\DateTimeZone::listIdentifiers(),true)||$ad->adSet?->campaign?->provider!==$account->provider)return ['status'=>'invalid_scope','reason'=>'Known consistent currency, provider and IANA timezone required.','signal'=>null];
  $today=now()->setTimezone($account->timezone);$start=$today->copy()->subDays(21)->toDateString();$end=$today->copy()->subDay()->toDateString();
  foreach($rows as$row)if($row->connected_ad_account_id!==$account->id||$row->ad_id!==$ad->id||$row->campaign_id!==$ad->adSet->campaign_id||$row->ad_set_id!==$ad->ad_set_id||$row->provider!==$account->provider||$row->currency!==$account->currency)return ['status'=>'invalid_scope','reason'=>'Ad parent/account/provider/currency chain inconsistent.','signal'=>null];
  if($problem=(new MetaDefinitionTransitionGuard)->problem($rows))return ['status'=>'insufficient_data','reason'=>$problem,'signal'=>null];
  $out=(new CreativeFatigueEngine)->analyze(array_map(fn($x)=>['date'=>$x->date->toDateString(),'spend'=>$x->spend,'impressions'=>$x->impressions,'clicks'=>$x->clicks,'conversions'=>$x->conversions,'frequency'=>$x->frequency],$rows));
  if($out['status']==='ok'&&($rows[0]->date->toDateString()!==$start||$rows[20]->date->toDateString()!==$end))$out=['status'=>'insufficient_data','signal'=>null,'reason'=>'History does not match the last21 completed account-local days.'];
  return $out+['ad_id'=>$ad->id,'ad_name'=>$ad->name,'creative_type'=>$ad->creative_type,'account_id'=>$account->id,'currency'=>$account->currency,'timezone'=>$account->timezone,'window'=>[$start,$end]];
 }
}
