<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\PerformanceMetric;
use App\Services\Analytics\CreativeFatigueEngine;
use Illuminate\Http\Request;

final class CreativeFatigueController extends Controller
{
    public function show(Request $r, CreativeFatigueEngine $engine)
    {
        $v = $r->validate(['ad_id' => 'required|integer|min:1']);
        $ad = Ad::with('adSet.campaign.connectedAdAccount')->findOrFail($v['ad_id']);
        $campaign = $ad->adSet?->campaign;
        $account = $campaign?->connectedAdAccount;
        abort_unless($account && $account->user_id === $r->user()->id, 404);
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem((int)$r->user()->id,null,(int)$account->id)){return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','reason'=>$originalProblem,'signal'=>null]]);}
        if(!in_array($account->timezone,\DateTimeZone::listIdentifiers(),true))return response()->json(['success'=>true,'data'=>['status'=>'invalid_scope','reason'=>'A verified IANA reporting timezone is required.','signal'=>null]]);
        $localNow=now()->setTimezone($account->timezone);
        $start = $localNow->copy()->subDays(21)->toDateString();
        $end = $localNow->copy()->subDay()->toDateString();
        $rows = PerformanceMetric::where('connected_ad_account_id', $account->id)->where('ad_id', $ad->id)->whereBetween('date', [$start, $end])->orderBy('date')->limit(22)->get();
        return response()->json(['success'=>true,'data'=>(new \App\Services\Analytics\AdFatigueReport)->build($ad,$account,$rows->all())]);
    }
}
