<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Services\Analytics\DiagnosticsEngine;
use Illuminate\Http\Request;

final class CampaignHealthController extends Controller
{
    public function show(Request $r, DiagnosticsEngine $engine)
    {
        $v = $r->validate(['provider' => 'required|in:meta,google,tiktok,snapchat,taboola,pinterest,reddit,spotify,linkedin,microsoft,amazon']);
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem((int)$r->user()->id,$v['provider'])){return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','reason'=>$originalProblem,'rows'=>[]]]);}
        $accounts = ConnectedAdAccount::where('user_id', $r->user()->id)->where('provider', $v['provider'])->get()->keyBy('id');
        $campaigns = Campaign::whereIn('connected_ad_account_id', $accounts->keys())->orderBy('id')->limit(101)->get();
        if ($campaigns->count() > 100) {
            return response()->json(['success' => true, 'data' => ['status' => 'local_limit', 'reason' => 'Health matrix local 100-campaign cap. Narrow the report population.', 'rows' => []]]);
        }
        $windows=[];
        foreach($accounts as$a){if(in_array($a->timezone,\DateTimeZone::listIdentifiers(),true)){$now=now()->setTimezone($a->timezone);$windows[$a->id]=[$now->copy()->subDays(28)->toDateString(),$now->copy()->subDay()->toDateString()];}}
        $all = $windows ? PerformanceMetric::whereIn('campaign_id',$campaigns->pluck('id'))->whereNull('ad_set_id')->whereNull('ad_id')->where(function($q)use($windows){foreach($windows as$id=>$window){$q->orWhere(fn($q)=>$q->where('connected_ad_account_id',$id)->whereBetween('date',$window));}})->orderBy('date')->limit(2801)->get() : collect();
        if ($all->count() > 2800) {
            return response()->json(['success' => true, 'data' => ['status' => 'local_limit', 'reason' => 'Campaign/day row limit exceeded.', 'rows' => []]]);
        }
        $out = [];
        foreach ($campaigns as $c) {
            $rows = $all->where('campaign_id', $c->id)->values();
            $a = $accounts[$c->connected_ad_account_id];
            $reason = null;
            if (! $a->currency || ! $a->timezone || ! in_array($a->timezone, \DateTimeZone::listIdentifiers(), true) || $c->provider !== $a->provider) {
                $reason = 'Known consistent currency, timezone and provider required.';
            } elseif ($rows->count() !== 28 || $rows->pluck('date')->map(fn ($d) => $d->toDateString())->unique()->count() !== 28) {
                $reason = 'Needs 28 distinct complete campaign-level days. No account/child substitution.';
            }
            foreach ($rows as $row) {
                if ($row->provider !== $a->provider || $row->currency !== $a->currency || $row->connected_ad_account_id !== $a->id) {
                    $reason = 'Campaign metric linkage/currency inconsistent.';
                }foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $key) {
                    if (! is_numeric($row->{$key}) || ! is_finite((float) $row->{$key}) || $row->{$key} < 0) {
                        $reason = 'Invalid source metrics.';
                    }
                }
            }
            $reason=$reason??(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($rows);
            if ($reason) {
                $diagnostic = ['health' => 'insufficient_data', 'decision_state' => 'INSUFFICIENT_DATA', 'reason' => $reason, 'classification' => null, 'signals' => []];
            } else {
                $totals = fn ($chunk) => array_combine(['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'], array_map(fn ($key) => (float) $chunk->sum($key), ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value']));
                $diagnostic = $engine->diagnose($totals($rows->slice(14)), $totals($rows->slice(0, 14)), ['days_current' => 14, 'days_baseline' => 14, 'entity' => 'campaign:'.$c->id]);
            }
            $out[] = ['campaign_id' => $c->id, 'campaign_name' => $c->name, 'account_id' => $a->id, 'currency' => $a->currency, 'timezone' => $a->timezone, 'window'=>$windows[$a->id]??null, 'diagnostic' => $diagnostic];
        }

        return response()->json(['success' => true, 'data' => ['status' => 'ok', 'type' => 'OBSERVED_DIAGNOSTIC', 'window' => null, 'window_semantics'=>'Each row uses the last28 completed days in its own account reporting timezone.', 'rows' => $out, 'note' => 'Per-campaign comparison of current 14 days with previous 14 days. Different currencies remain separate rows, never ranked by nominal value. Rules suggest checks, not causal verdicts. Recent conversion attribution can restate.']]);
    }
}
