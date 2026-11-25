<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Services\Analytics\AnomalyDetector;
use App\Services\Analytics\DataQualityEngine;
use App\Services\Analytics\DiagnosticsEngine;
use App\Services\Analytics\ForecastingEngine;
use App\Services\Analytics\KpiEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics surfaces for the new engines (Master Spec sections
 * 10-12, 26, 29, 32-38, 65-67). Every number is typed (ACTUAL / EXPECTED)
 * and carries its provenance; abstentions are explicit.
 */
class AnalyticsDeepController extends Controller
{
    private const PROVIDERS = ['meta', 'google', 'tiktok', 'snapchat', 'taboola', 'pinterest', 'reddit', 'spotify', 'linkedin', 'microsoft', 'amazon'];
    private const METRICS = ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'ctr', 'cpc', 'cpm', 'cvr', 'cpa', 'roas'];

    /** Rule-based first-party journey allocation, never provider-total deduplication. */
    public function attribution(Request $request, \App\Services\Analytics\AttributionEngine $engine): JsonResponse
    {
        $v=$request->validate([
            'compare_models'=>'sometimes|boolean',
            'model'=>'required|in:first_click,last_click,linear,time_decay,position_based,data_driven',
            'half_life_days'=>'nullable|numeric|gt:0|max:3650',
            'journeys'=>'required|array|max:1000',
            'journeys.*.conversion_id'=>'required|string|max:128',
            'journeys.*.value'=>'required|numeric|min:0|max:999999999999',
            'journeys.*.currency'=>'required|string|size:3',
            'journeys.*.converted_at'=>'required|string|max:40',
            'journeys.*.touches'=>'required|array|min:1|max:100',
            'journeys.*.touches.*.event_id'=>'required|string|max:128',
            'journeys.*.touches.*.channel'=>'required|string|max:64',
            'journeys.*.touches.*.at'=>'required|string|max:40',
        ]);
        try { $result=($v['compare_models']??false)?$engine->compare($v['journeys'],['half_life_days'=>$v['half_life_days']??7]):$engine->attribute($v['journeys'],$v['model'],['half_life_days'=>$v['half_life_days']??7]); }
        catch (\InvalidArgumentException $e) { return response()->json(['success'=>false,'message'=>$e->getMessage()],422); }
        return response()->json(['success'=>true,'data'=>$result]);
    }

    /** Spec 10/65/67: deterministic data-quality scores + the optimization gate. */
    public function dataQuality(Request $request, DataQualityEngine $dq): JsonResponse
    {
        $userId = $request->user()->id;
        $accounts = ConnectedAdAccount::where('user_id', $userId)->get();
        $orchestrator = app(\App\Services\OperationsResearch\OptimizationOrchestrator::class);
        $block = $orchestrator->dataQualityBlock($userId, $accounts);
        return response()->json(['success' => true, 'data' => $block]);
    }

    /** Spec 11-12: KPIs typed ACTUAL with lineage, per platform and total. */
    public function kpis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'completed_days'=>['nullable','integer','min:1','max:90','prohibits:start_date,end_date'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'provider' => ['nullable', 'string', 'in:' . implode(',', self::PROVIDERS)],
        ]);
        $window=null;
        if(isset($validated['completed_days'])){$window=app(\App\Services\Analytics\ReportingWindow::class)->completed($request->user()->id,(int)$validated['completed_days'],$validated['provider']??null);if($window['status']!=='ok')return response()->json(['success'=>true,'data'=>$window+['groups'=>[]]]);}
        $start = $window['start'] ?? $validated['start_date'] ?? Carbon::now()->subDays(30)->format('Y-m-d');
        $end = $window['end'] ?? $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $ids = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');

        $scopeProblem=app(\App\Services\Analytics\MetricScopeGuard::class)->problem($request->user()->id,$start,$end,$validated['provider']??null,null,false,false);
        if ($scopeProblem) { return response()->json(['success'=>true,'data'=>['status'=>'not_computable','reason'=>$scopeProblem,'groups'=>[]]]); }

        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $ids)
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->when($validated['provider'] ?? null, fn($q, $p) => $q->where('provider', $p))
            ->select('provider', 'currency', DB::raw('SUM(spend) spend'), DB::raw('SUM(impressions) impressions'), DB::raw('SUM(clicks) clicks'), DB::raw('SUM(conversions) conversions'), DB::raw('SUM(conversion_value) conversion_value'), DB::raw('COUNT(DISTINCT date) days'), DB::raw('COUNT(DISTINCT currency) currencies'), DB::raw('MIN(currency) currency'))
            ->groupBy('provider', 'currency')->get();

        $sourceRows=PerformanceMetric::whereIn('connected_ad_account_id',$ids)->whereBetween('date',[$start,$end])->when($validated['provider']??null,fn($q,$p)=>$q->where('provider',$p))->get(['provider','currency','metadata']);
        $byProvider = [];
        foreach ($rows as $r) {
            $kpi = KpiEngine::fromTotals((float) $r->spend, (float) $r->impressions, (float) $r->clicks, (float) $r->conversions, (float) $r->conversion_value, (string) $r->currency);
            $reason=(new \App\Services\Analytics\OutcomeSemanticGuard)->problem($sourceRows->where('provider',$r->provider)->where('currency',$r->currency));
            if($reason)foreach(['conversions','conversion_value','cvr','cpa','roas']as$key)$kpi[$key]=\App\Services\Analytics\DTO\MetricValue::unavailable('ACTUAL',$reason);
            // Exact arithmetic on DATABASE decimal/integer totals, without claiming lost source precision was recovered.
            $recorded = \App\Services\Analytics\RecordedKpiArithmetic::fromTotals([
                'spend'=>$r->getRawOriginal('spend'),'impressions'=>$r->getRawOriginal('impressions'),
                'clicks'=>$r->getRawOriginal('clicks'),'conversions'=>$reason?null:$r->getRawOriginal('conversions'),
                'conversion_value'=>$reason?null:$r->getRawOriginal('conversion_value')]);
            $originalDelivery = (new \App\Services\Analytics\RecordedDeliveryKpis)->forGroup((int)$request->user()->id, (string)$r->provider, (string)$r->currency, $start, $end);
            if ($originalDelivery !== null) { $recorded = $originalDelivery; }
            $byProvider[$r->provider . '/' . $r->currency] = [
                'days_with_data' => (int) $r->days,
                'metrics' => collect($kpi)->map(fn($m) => $m->toArray())->all(),
                'recorded_arithmetic' => $recorded,
            ];
        }
        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $start, 'end_date' => $end, 'timezone'=>$window['timezone']??null, 'window_semantics'=>$window?'completed_account_local_days':'explicit_observed_date_labels',
                'groups' => $byProvider,
                'typing' => 'All values are ACTUAL (observed). Cross-platform sums are platform-reported and may overlap; never unique totals.',
            ],
        ]);
    }

    /** Spec 29: forecast with prediction intervals, per provider+metric. */
    public function forecast(Request $request, ForecastingEngine $engine): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'metric' => ['required', 'string', 'in:spend,impressions,clicks,conversions,conversion_value'],
            'days' => ['nullable', 'integer', 'min:14', 'max:90'],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $window=app(\App\Services\Analytics\ReportingWindow::class)->completed($request->user()->id,$days,$validated['provider']);
        if($window['status']!=='ok')return response()->json(['success'=>true,'data'=>$window+['points'=>[],'type'=>'EXPECTED']]);
        $original=(new \App\Services\Analytics\RecordedDeliverySeries)->forWindow((int)$request->user()->id,$validated['provider'],$window['start'],$window['end']);
        if($original!==null){return response()->json(['success'=>true,'data'=>[
            'status'=>'insufficient_data','type'=>'EXPECTED','metric'=>$validated['metric'],'provider'=>$validated['provider'],'window'=>$window,
            'reason'=>'Original returned-row source population and canonical outcomes are not qualified for forecasting. Rounded legacy rows are not substituted.',
            'points'=>[],'source_snapshot_ids'=>$original['snapshot_ids'],'calibration'=>'not_established','probability_of_success'=>null,
        ]]);}
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem((int)$request->user()->id,$validated['provider']??null)){return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','reason'=>$originalProblem,'points'=>[],'type'=>'EXPECTED']]);}
        $ids = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');
        $col = $validated['metric'];
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $ids)
            ->where('provider', $validated['provider'])
            ->whereDate('date', '>=', $window['start'])
            ->whereDate('date', '<=', $window['end'])
            ->select('date', DB::raw("SUM($col) as v"), DB::raw('COUNT(DISTINCT currency) currencies'), DB::raw('MIN(currency) currency'))
            ->groupBy('date')->orderBy('date')->get();
        $problem=app(\App\Services\Analytics\MetricScopeGuard::class)->problem($request->user()->id,$window['start'],$window['end'],$validated['provider'],null,true) ?? $this->historyProblem($rows,$days);
        if ($problem !== null) {
            return response()->json(['success' => true, 'data' => ['status' => 'insufficient_data', 'reason' => $problem, 'points' => [], 'type' => 'EXPECTED']]);
        }
        $result = $engine->forecast($rows->map(fn($r) => (float) $r->v)->all(), (int) ($validated['horizon'] ?? 7));
        $result['window']=$window;
        $result['metric'] = $col;
        $result['provider'] = $validated['provider'];
        $result['type'] = 'EXPECTED';
        return response()->json(['success' => true, 'data' => $result]);
    }

    /** Spec 38: anomaly flags per provider+metric. */
    public function anomalies(Request $request, AnomalyDetector $detector): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'metric' => ['required', 'string', 'in:spend,impressions,clicks,conversions,conversion_value'],
            'days' => ['nullable', 'integer', 'min:8', 'max:90'],
        ]);
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem((int)$request->user()->id,$validated['provider']??null)){return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','reason'=>$originalProblem,'dates'=>[]]]);}
        $days = (int) ($validated['days'] ?? 30);
        $window=app(\App\Services\Analytics\ReportingWindow::class)->completed($request->user()->id,$days,$validated['provider']);
        if($window['status']!=='ok')return response()->json(['success'=>true,'data'=>$window+['dates'=>[]]]);
        $ids = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');
        $col = $validated['metric'];
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $ids)
            ->where('provider', $validated['provider'])
            ->whereDate('date', '>=', $window['start'])
            ->whereDate('date', '<=', $window['end'])
            ->select('date', DB::raw("SUM($col) as v"), DB::raw('COUNT(DISTINCT currency) currencies'), DB::raw('MIN(currency) currency'))
            ->groupBy('date')->orderBy('date')->get();
        $problem=app(\App\Services\Analytics\MetricScopeGuard::class)->problem($request->user()->id,$window['start'],$window['end'],$validated['provider'],null,true) ?? $this->historyProblem($rows,$days);
        if ($problem !== null) {
            return response()->json(['success' => true, 'data' => ['status' => 'insufficient_data', 'reason' => $problem, 'dates' => []]]);
        }
        $result = $detector->analyze($rows->map(fn($r) => (float) $r->v)->all());
        $result['window']=$window;
        $result['dates'] = $rows->map(fn($r) => substr((string) $r->date, 0, 10))->all();
        $result['metric'] = $col;
        $result['provider'] = $validated['provider'];
        return response()->json(['success' => true, 'data' => $result]);
    }

    /** Spec 32-37/66: funnel diagnostics, current 14 days vs prior 14 days. */
    public function diagnostics(Request $request, DiagnosticsEngine $engine): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:' . implode(',', self::PROVIDERS)],
            'campaign_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:7', 'max:30'],
        ]);
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem((int)$request->user()->id,$validated['provider']??null)){return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','reason'=>$originalProblem,'signals'=>[],'decision_state'=>'INSUFFICIENT_DATA','classification'=>null]]);}
        $days = (int) ($validated['days'] ?? 14);
        $ids = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');

        $sum = function (string $from, string $to) use ($ids, $validated) {
            return PerformanceMetric::whereIn('connected_ad_account_id', $ids)
                ->when($validated['provider'] ?? null, fn($q, $p) => $q->where('provider', $p))
                ->when($validated['campaign_id'] ?? null, fn($q, $c) => $q->where('campaign_id', $c))
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
                ->select(DB::raw('COALESCE(SUM(spend),0) spend'), DB::raw('COALESCE(SUM(impressions),0) impressions'), DB::raw('COALESCE(SUM(clicks),0) clicks'), DB::raw('COALESCE(SUM(conversions),0) conversions'), DB::raw('COALESCE(SUM(conversion_value),0) conversion_value'), DB::raw('COUNT(DISTINCT date) days'), DB::raw('COUNT(DISTINCT currency) currencies'), DB::raw('MIN(currency) currency'))
                ->first();
        };
        $window=app(\App\Services\Analytics\ReportingWindow::class)->completed($request->user()->id,2*$days,$validated['provider']??null,$validated['campaign_id']??null);
        if($window['status']!=='ok')return response()->json(['success'=>true,'data'=>$window+['decision_state'=>'INSUFFICIENT_DATA','signals'=>[],'classification'=>null]]);
        $curEnd=$window['end'];$baseStart=$window['start'];$curStart=Carbon::parse($curEnd)->subDays($days-1)->toDateString();$baseEnd=Carbon::parse($curStart)->subDay()->toDateString();
        $scopeProblem=app(\App\Services\Analytics\MetricScopeGuard::class)->problem($request->user()->id,$baseStart,$curEnd,$validated['provider']??null,$validated['campaign_id']??null,true);
        if ($scopeProblem) { return response()->json(['success'=>true,'data'=>['status'=>'insufficient_data','decision_state'=>'INSUFFICIENT_DATA','reason'=>$scopeProblem,'signals'=>[],'classification'=>null]]); }
        $cur = $sum($curStart, $curEnd);
        $base = $sum($baseStart, $baseEnd);

        if ((int) $cur->currencies !== 1 || (int) $base->currencies !== 1 || $cur->currency !== $base->currency || (int) $cur->days !== $days || (int) $base->days !== $days) {
            return response()->json(['success' => true, 'data' => ['status' => 'insufficient_data', 'decision_state' => 'INSUFFICIENT_DATA', 'reason' => 'Diagnostics require complete comparable windows in a single shared currency.', 'signals' => [], 'classification' => null, 'windows' => ['current' => [$curStart, $curEnd], 'baseline' => [$baseStart, $baseEnd]]]]);
        }
        $entity = $validated['campaign_id'] ?? null
            ? 'campaign:' . $validated['campaign_id']
            : ($validated['provider'] ?? 'portfolio');

        $r = $engine->diagnose(
            ['spend' => (float) $cur->spend, 'impressions' => (float) $cur->impressions, 'clicks' => (float) $cur->clicks, 'conversions' => (float) $cur->conversions, 'conversion_value' => (float) $cur->conversion_value],
            ['spend' => (float) $base->spend, 'impressions' => (float) $base->impressions, 'clicks' => (float) $base->clicks, 'conversions' => (float) $base->conversions, 'conversion_value' => (float) $base->conversion_value],
            ['days_current' => (int) $cur->days, 'days_baseline' => (int) $base->days, 'entity' => (string) $entity]
        );
        $r['windows'] = ['current' => [$curStart, $curEnd], 'baseline' => [$baseStart, $baseEnd]];
        return response()->json(['success' => true, 'data' => $r]);
    }
    /** Never silently compress missing calendar days into an equally spaced series. */
    private function historyProblem($rows, int $days): ?string
    {
        if ($rows->count() !== $days) {
            return 'Complete consecutive daily history ending yesterday is required; missing days are not zero-filled.';
        }
        if ($rows->contains(fn($r) => (int) $r->currencies !== 1 || empty($r->currency)) || $rows->pluck('currency')->unique()->count() !== 1) {
            return 'A single known currency across the history is required; mixed-currency values are not summed.';
        }
        return null;
    }

    public function audience(Request $request): JsonResponse
    {
        $v = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $ids = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $ids)
            ->whereDate('date', '>=', now()->subDays((int) ($v['days'] ?? 30))->toDateString())
            ->whereDate('date', '<=', now()->subDay()->toDateString())
            ->where(fn($q) => $q->whereNotNull('reach')->orWhereNotNull('frequency'))
            ->orderBy('date')->limit(1001)
            ->get(['connected_ad_account_id', 'campaign_id', 'ad_set_id', 'ad_id', 'provider', 'date', 'reach', 'frequency']);
        return response()->json(['success' => true, 'data' => [
            'rows' => $rows->take(1000), 'truncated' => $rows->count() > 1000,
            'note' => 'Platform-reported estimates at each exact entity/day scope. Reach must not be summed across days, campaigns or platforms as unique people. Missing values remain null. Frequency is not additive. Availability varies by platform and permissions.',
        ]]);
    }

}
