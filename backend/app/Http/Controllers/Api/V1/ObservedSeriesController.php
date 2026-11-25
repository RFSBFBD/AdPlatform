<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Services\Analytics\KpiEngine;
use App\Services\Analytics\MetricScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ObservedSeriesController extends Controller
{
    public function show(Request $r)
    {
        $v = $r->validate(['provider' => 'required|in:meta,google,tiktok,snapchat,taboola,pinterest,reddit,spotify,linkedin,microsoft,amazon', 'days' => 'nullable|integer|min:7|max:90']);
        $days = (int) ($v['days'] ?? 30);
        $window=app(\App\Services\Analytics\ReportingWindow::class)->completed($r->user()->id,$days,$v['provider']);
        if($window['status']!=='ok')return response()->json(['success'=>true,'data'=>$window+['points'=>[]]]);
        $start=$window['start'];$end=$window['end'];

        return DB::transaction(function () use ($r, $v, $start, $end) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $original=(new \App\Services\Analytics\RecordedDeliverySeries)->forWindow((int)$r->user()->id,$v['provider'],$start,$end);
            if($original!==null){return response()->json(['success'=>true,'data'=>$original]);}
            $problem = app(MetricScopeGuard::class)->problem($r->user()->id, $start, $end, $v['provider'], null, true);
            if ($problem) {
                return response()->json(['success' => true, 'data' => ['status' => 'not_computable', 'reason' => $problem, 'points' => []]]);
            }
            $accounts = ConnectedAdAccount::where('user_id', $r->user()->id)->where('provider', $v['provider'])->get(['id', 'currency', 'timezone']);
            $tz = $accounts->pluck('timezone')->unique();
            $curr = $accounts->pluck('currency')->unique();
            if ($tz->count() !== 1 || ! $tz->first() || $curr->count() !== 1 || ! $curr->first()) {
                return response()->json(['success' => true, 'data' => ['status' => 'incomparable_scope', 'reason' => 'One known reporting timezone and currency required.', 'points' => []]]);
            }
            $rows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))->whereBetween('date', [$start, $end])->orderBy('date')->limit(30001)->get();
            if ($rows->count() > 30000) {
                return response()->json(['success' => true, 'data' => ['status' => 'local_limit', 'reason' => 'Daily series local30000-row cap exceeded.', 'points' => []]]);
            }
            foreach ($rows as $row) {
                if ($row->provider !== $v['provider'] || $row->currency !== $curr->first()) {
                    return response()->json(['success' => true, 'data' => ['status' => 'invalid_data', 'reason' => 'Metric currency/provider disagrees with its connected account.', 'points' => []]]);
                }foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $key) {
                    if (! is_numeric($row->{$key}) || ! is_finite((float) $row->{$key}) || $row->{$key} < 0) {
                        return response()->json(['success' => true, 'data' => ['status' => 'invalid_data', 'reason' => 'Missing, nonfinite or negative source values.', 'points' => []]]);
                    }
                }
            }
            $points = [];
            foreach ($rows->groupBy(fn ($x) => $x->date->toDateString()) as $date => $group) {
                $kpi = KpiEngine::fromTotals(...[...(array_map(fn ($key) => (float) $group->sum($key), ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'])), $curr->first()]);
                $exactTotals=[];
                foreach(['spend','impressions','clicks','conversions','conversion_value']as$key){$sum=\Brick\Math\BigDecimal::zero();foreach($group as$source){$sum=$sum->plus(\App\Services\Decision\Accounting\ExactValue::of($source->getRawOriginal($key),in_array($key,['impressions','clicks'],true))->decimal());}$exactTotals[$key]=(string)$sum;}
                $points[] = ['date' => $date, 'metrics' => collect($kpi)->map(fn ($m) => $m->value)->all(), 'recorded_arithmetic'=>\App\Services\Analytics\RecordedKpiArithmetic::fromTotals($exactTotals)];
            }

            return response()->json(['success' => true, 'data' => ['status' => 'ok', 'type' => 'ACTUAL', 'provider' => $v['provider'], 'currency' => $curr->first(), 'timezone' => $tz->first(), 'start' => $start, 'end' => $end, 'points' => $points, 'note' => 'Platform-reported observations, not causal response. Each point is the portfolio total for that provider-local date; Account-local date cutoff selects completed reporting days. Ratios are computed after daily aggregation, never averaged. Missing or zero-denominator ratios are null.']]);
        });
    }
}
