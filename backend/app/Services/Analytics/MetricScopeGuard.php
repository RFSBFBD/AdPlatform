<?php
namespace App\Services\Analytics;
use App\Models\{ConnectedAdAccount,PerformanceMetric};
/** Prevent cross-level addition and changing account populations in statistical comparisons. */
final class MetricScopeGuard
{
    public function problem(int $userId,string $start,string $end,?string $provider=null,?int $campaignId=null,bool $requireEveryAccountDay=false,bool $outcomeSemantics=true): ?string
    {
        $accounts=ConnectedAdAccount::where('user_id',$userId)->when($provider,fn($q)=>$q->where('provider',$provider))->get(['id','provider','currency'])->keyBy('id');
        $rows=PerformanceMetric::whereIn('connected_ad_account_id',$accounts->pluck('id'))->when($provider,fn($q)=>$q->where('provider',$provider))->when($campaignId,fn($q)=>$q->where('campaign_id',$campaignId))->whereBetween('date',[$start,$end])->limit(30001)->get(['connected_ad_account_id','date','campaign_id','ad_set_id','ad_id','currency','provider','spend','impressions','clicks','conversions','conversion_value','metadata']);
        if($rows->count()>30000)return 'Daily analysis exceeds the local30000-row scope validation limit. Narrow the date/provider scope.';
        if ($rows->isEmpty()) { return 'No source rows for this scope. Missing observations are not zero.'; }
        $campaigns=\App\Models\Campaign::whereIn('id',$rows->pluck('campaign_id')->filter()->unique())->get()->keyBy('id');
        $sets=\App\Models\AdSet::whereIn('id',$rows->pluck('ad_set_id')->filter()->unique())->get()->keyBy('id');
        $ads=\App\Models\Ad::whereIn('id',$rows->pluck('ad_id')->filter()->unique())->get()->keyBy('id');
        foreach($rows as$row){
            $a=$accounts[$row->connected_ad_account_id];
            if($row->provider!==$a->provider||!$a->currency||$row->currency!==$a->currency)return 'Metric provider/currency disagrees with its owned account.';
            if($row->campaign_id!==null||$row->ad_set_id!==null||$row->ad_id!==null){
                $c=$campaigns[$row->campaign_id]??null;
                if(!$c||$c->connected_ad_account_id!==$a->id||$c->provider!==$a->provider)return 'Metric campaign/account/provider chain is inconsistent.';
                if($row->ad_set_id!==null||$row->ad_id!==null){$set=$sets[$row->ad_set_id]??null;if(!$set||$set->campaign_id!==$c->id)return 'Metric ad-set parent chain is inconsistent.';}
                if($row->ad_id!==null){$ad=$ads[$row->ad_id]??null;if(!$ad||$ad->ad_set_id!==$row->ad_set_id)return 'Metric ad parent chain is inconsistent.';}
            }
            foreach(['spend','impressions','clicks','conversions','conversion_value']as$key)if(!is_numeric($row->{$key})||!is_finite((float)$row->{$key})||$row->{$key}<0)return 'Missing, negative or nonfinite metric operands cannot be aggregated.';
        }
        foreach ($rows->groupBy(fn($r)=>$r->connected_ad_account_id.'|'.$r->date->toDateString()) as $day) {
            $levels=$day->map(fn($r)=>$r->ad_id?'ad':($r->ad_set_id?'ad_set':($r->campaign_id?'campaign':'account')))->unique();
            if ($levels->count()>1) { return 'Overlapping hierarchy levels for the same account/day cannot be added without double-counting.'; }
            $keys=$day->map(fn($r)=>implode('|',[$r->campaign_id,$r->ad_set_id,$r->ad_id]));
            if ($keys->unique()->count()!==$keys->count()) { return 'Duplicate entity/day rows prevent a reliable comparison.'; }
        }
        if ($requireEveryAccountDay) {
            $expected=$campaignId?$rows->pluck('connected_ad_account_id')->unique():$accounts->pluck('id');
            $days=(int)\Carbon\CarbonImmutable::parse($start)->diffInDays(\Carbon\CarbonImmutable::parse($end))+1;
            foreach ($expected as $id) {
                if ($rows->where('connected_ad_account_id',$id)->pluck('date')->map(fn($d)=>$d->toDateString())->unique()->count()!==$days) { return 'Every selected account must have data on every day. Changing account populations and missing account-days are not zero-filled.'; }
            }
        }
        if($definitionProblem=(new MetaDefinitionTransitionGuard)->problem($rows,true,$outcomeSemantics))return $definitionProblem;
        return null;
    }
}
