<?php
namespace App\Services\Analytics;
use App\Models\ConnectedAdAccount;
/** Daily provider buckets cannot be aligned by relabeling unlike reporting timezones. */
final class ReportingWindow
{
 public function completed(int $userId,int $days,?string $provider=null,?int $campaignId=null):array{
  $accounts=ConnectedAdAccount::where('user_id',$userId)->when($provider,fn($q)=>$q->where('provider',$provider))->when($campaignId,fn($q)=>$q->whereHas('campaigns',fn($q)=>$q->where('id',$campaignId)))->get(['id','timezone']);
  if($accounts->isEmpty())return ['status'=>'not_computable','reason'=>'No owned accounts for the selected reporting scope.'];
  $tz=$accounts->pluck('timezone')->unique();
  if($tz->count()!==1||!in_array($tz->first(),\DateTimeZone::listIdentifiers(),true))return ['status'=>'incomparable_scope','reason'=>'One verified IANA reporting timezone required for a shared daily window. Unlike or unknown timezones are not aligned by date labels.'];
  $today=now()->setTimezone($tz->first());return ['status'=>'ok','timezone'=>$tz->first(),'start'=>$today->copy()->subDays($days)->toDateString(),'end'=>$today->copy()->subDay()->toDateString(),'today'=>$today->toDateString()];
 }
}
