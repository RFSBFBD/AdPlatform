<?php

namespace App\Services\OperationsResearch;

use App\Models\ConnectedAdAccount;
use App\Models\OptimizationRun;
use App\Models\PerformanceMetric;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Analytics\DataQualityEngine;
use App\Services\Analytics\ResponseCurveFitter;
use App\Services\Analytics\ScenarioEngine;
use App\Services\OperationsResearch\DTOs\OptimizationResultDto;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one full, reproducible optimization pass (Master Spec sections
 * 44-48, 60-67): data quality gate -> curve mode (assumed | fitted) ->
 * optimization -> sensitivity/stability -> stress range -> structured
 * recommendations -> reproducibility record.
 *
 * Read-only with respect to ad platforms; the only write is the local
 * optimization_runs audit row (a reproducibility ledger, not a campaign change).
 */
class OptimizationOrchestrator
{
    public const ENGINE = 'adplatform-or';
    public const ENGINE_VERSION = '7.5.0';

    public function __construct(
        private AdvertisingOptimizationService $assumedService,
        private ResponseCurveFitter $fitter,
        private DataQualityEngine $quality,
        private ScenarioEngine $scenarios,
    ) {}

    /**
     * @return array payload for the API (includes run_id)
     */
    public function run(int $userId, array $options): array
    {
        if (DB::transactionLevel()>0) { return $this->runConsistent($userId,$options); }
        return DB::transaction(function() use ($userId,$options) {
            if (DB::getDriverName()==='pgsql') { DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
            return $this->runConsistent($userId,$options);
        });
    }

    private function runConsistent(int $userId,array $options): array
    {
        $curveMode = $options['curve_mode'] ?? 'assumed';
        $accounts = ConnectedAdAccount::where('user_id', $userId)->get(['id', 'provider', 'timezone']);

        // --- Data quality block (report + spec-67 gate) ---
        $dqBlock = $this->dataQualityBlock($userId, $accounts);

        $preflightWindow = $curveMode === 'assumed' ? ($this->assumedService->resolveWindow($userId) ?? $dqBlock['window']) : $dqBlock['window'];
        $sourceRows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->whereBetween('date', [$preflightWindow['start'], $preflightWindow['end']])->orderBy('id')->limit(30001)->get();
        $preflightService = new ObjectiveEstimandPreflight;
        $preflight = $preflightService->inspect($options, $sourceRows, $preflightWindow);
        if ($sourceRows->count() > 30000) {
            $preflight['status'] = 'local_limit';
            $preflight['reason'] = 'Preflight exceeds local 30000-row cap. Source evidence is incomplete; no scenario produced.';
        }
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem($userId)){$preflight['status']='unqualified_original_source';$preflight['reason']=$originalProblem;}
        $this->lastCurves = [];
        $this->lastModelSummary = null;
        if ($preflight['status'] !== 'ready_for_source_validation') {
            $result = OptimizationResultDto::abstain($preflight['status'], $preflight['reason'], (float) ($options['budget'] ?? 0))->withGates(['window' => $preflightWindow]);
        } elseif ($curveMode === 'fitted') {
            $result = $this->fittedRun($userId, $accounts, $options);
        } else {
            $result = $this->assumedService->optimizeForUser($userId, $options);
            // Wrap the assumed exponential curves for sensitivity/stress tooling.
            // Their uncertainty is NOT estimable: stress stays honestly partial.
            $this->lastCurves = [];
            foreach ($this->assumedService->lastChannelParams as $cp) {
                $this->lastCurves[] = [
                    'channel' => $cp->channel,
                    'display_name' => $cp->displayName,
                    'curve' => ResponseCurve::saturation($cp->alpha, $cp->beta, max(1.0, $cp->currentSpend)),
                    'current_spend' => $cp->currentSpend,
                    'current_revenue' => $cp->currentRevenue,
                    'min' => $cp->minSpend,
                    'max' => $cp->maxSpend,
                    'validation_rmse_rel' => null,
                ];
            }
            $this->lastModelSummary = null;
        }
        $payload = $result->toArray();

        // Spec 67: never optimize below the data-quality threshold.
        if ($payload['status'] === 'ok' && !($dqBlock['portfolio']['optimize_allowed'] ?? false)) {
            $result = OptimizationResultDto::abstain(
                'insufficient_data_quality',
                (string) ($dqBlock['portfolio']['gate_reason'] ?? 'Data quality below the optimization threshold.'),
                (float) $payload['total_budget'],
                $payload['warnings'] ?? [],
                $payload['data_sources'] ?? []
            );
            $payload = $result->toArray();
        }
        $payload['data_quality'] = $dqBlock;
        $payload['objective'] = $options['objective'] ?? null;
        $payload['outcome_basis'] = $options['outcome_basis'] ?? null;
        $payload['objective_preflight'] = $preflight;
        $payload['descriptive_reported_sources'] = $sourceRows->count() > 30000
            ? ['groups' => [], 'status' => 'local_limit'] : $preflightService->descriptive($sourceRows);
        $payload['objective_limits'] = 'Explicit reported-credit objective only, not business revenue, unique events or profit. Source/model gates are independent of intent.';

        // --- Sensitivity / stability / stress (only on a produced scenario) ---
        $curves = $this->lastCurves;
        if ($payload['status'] === 'ok' && $curves !== []) {
            $budget = (float) $payload['total_budget'];
            $solver = function (array $chs, float $b) use ($options) {
                $opt = new GenericCurveOptimizer();
                $r = $opt->optimize($b, $chs, ['budget_mode' => $options['budget_mode'] ?? 'exact', 'min_marginal_roas' => $options['min_marginal_roas'] ?? null]);
                if ($r->status !== 'ok') {
                    return null;
                }
                $out = [];
                foreach ($r->channelAllocations as $a) {
                    $out[$a['channel']] = (float) $a['optimal_spend'];
                }
                return $out;
            };
            if ($curveMode==='fitted') {
                $frontier=[];$channelCurves=[];
                foreach ([0.25,0.5,0.75,1.0,1.25,1.5,1.75,2.0] as $factor) {
                    $b=$budget*$factor;
                    $profile=$options['profile']??[];$cap=isset($profile['budget_cap']) && in_array($profile['budget_cap_horizon']??null,['daily','per_30_days'],true)?$profile['budget_cap']*($profile['budget_cap_horizon']==='daily'?30:1):null;
                    if ($cap!==null && $b>$cap) {continue;}
                    $r=(new GenericCurveOptimizer())->optimize($b,$curves,['budget_mode'=>$options['budget_mode']??'exact','min_marginal_roas'=>$options['min_marginal_roas']??null]);
                    if ($r->status==='ok') { $frontier[]=['budget'=>$b,'expected_revenue'=>$r->optimalTotalRevenue,'is_current'=>abs($factor-1)<1e-9,'type'=>'SCENARIO']; }
                }
                foreach ($curves as $ch) {
                    $pts=[];for($i=0;$i<=20;$i++) {$x=$ch['max']*$i/20;$pts[]=['spend'=>$x,'modeled_value'=>$ch['curve']->revenueAt($x),'marginal_return'=>$ch['curve']->marginalAt($x),'outside_support'=>$x<$ch['curve']->supportMinSpend() || $x>$ch['curve']->supportMaxSpend()];}
                    $channelCurves[]=['channel'=>$ch['channel'],'model'=>$ch['curve']->name(),'support_min'=>$ch['curve']->supportMinSpend(),'support_max'=>$ch['curve']->supportMaxSpend(),'points'=>$pts];
                }
                $payload['frontier_curve']=$frontier;
                $payload['response_curve_charts']=$channelCurves;
            }
            $payload['sensitivity'] = $this->scenarios->sensitivity($curves, $budget, $solver);
            $allocMap = [];
            foreach ($payload['channel_allocations'] as $a) {
                $allocMap[$a['channel']] = (float) $a['optimal_spend'];
            }
            $payload['stress'] = $this->scenarios->stressRange($curves, $allocMap);
        } else {
            $payload['sensitivity'] = ['status' => 'not_computable', 'reason' => 'No scenario was produced, so there is nothing to perturb.'];
            $payload['stress'] = ['status' => 'not_computable', 'reason' => 'No scenario was produced.'];
        }

        if (isset($options['custom_allocation'])) {
            $custom=$options['custom_allocation'];
            $known=array_column($curves,'channel');
            $customPeriod=[];
            foreach ($custom as $name=>$amount) { $customPeriod[$name]=(float)$amount*(($options['budget_horizon']??null)==='daily'?30:1); }
            if ($payload['status']!=='ok' || array_diff(array_keys($customPeriod),$known) || array_diff($known,array_keys($customPeriod))) {
                $payload['custom_scenario']=['status'=>'not_computable','reason'=>'A valid base model and exactly one allocation for every included channel are required.'];
            } elseif (array_sum($customPeriod)>(float)$payload['total_budget']+.005) {
                $payload['custom_scenario']=['status'=>'infeasible','reason'=>'Custom allocation exceeds the stated period budget.'];
            } else {
                $payload['custom_scenario']=$this->scenarios->evaluate($curves,$customPeriod);
            }
        }

        if (isset($options['target_reported_value'])) {
            $payload['target_scenario']=$payload['status']==='ok'
                ?app(TargetValueOptimizer::class)->optimize($curves,(float)$options['target_reported_value'],(float)$payload['total_budget'])
                :['status'=>'not_computable','reason'=>'A valid quality-gated base model is required.'];
        }

        if (isset($options['robust_bounds'])) {
            $payload['robust_scenario']=$payload['status']==='ok'
                ?app(BoxRobustOptimizer::class)->optimize($curves,(float)$payload['total_budget'],$options['robust_bounds'],['budget_mode'=>$options['budget_mode']??'exact','min_marginal_roas'=>$options['min_marginal_roas']??null])
                :['status'=>'not_computable','reason'=>'A valid quality-gated base model is required.'];
        }

        // --- Structured recommendations (spec 58-59); legacy text cards kept for the UI ---
        $payload['structured_recommendations'] = $this->structureRecommendations($payload, $curveMode);

        // --- Reproducibility record (spec 44-45) ---
        $fingerprint = $this->datasetFingerprint($userId, $accounts);
        $inputHash = hash('sha256', json_encode(['options' => $options, 'dataset' => $fingerprint]));
        $archiveRows=PerformanceMetric::whereIn('connected_ad_account_id',$accounts->pluck('id'))
            ->whereDate('date','>=',$preflightWindow['start'])
            ->whereDate('date','<=',$preflightWindow['end'])->orderBy('id')->limit(100001)->get();
        $snapshot=count($archiveRows)>100000?null:[
            'schema_version'=>2,'objective_preflight'=>$preflight,'curve_mode'=>$curveMode,'engine_version'=>self::ENGINE_VERSION,
            'window'=>$preflightWindow,'accounts'=>$accounts->sortBy('id')->values()->toArray(),
            'source_rows'=>$archiveRows->map(fn($r)=>$r->getAttributes())->toArray(),
            'configuration'=>['analytics'=>config('analytics'),'or'=>config('or')],
            'channels'=>array_map(fn($ch)=>array_merge(array_diff_key($ch,['curve'=>true]),[
                'curve'=>['name'=>$ch['curve']->name(),'params'=>$ch['curve']->parameters(),'support_min'=>method_exists($ch['curve'],'supportMinSpend')?$ch['curve']->supportMinSpend():0,'support_max'=>$ch['curve']->supportMaxSpend()]
            ]),$curves),
        ];
        $payload['archive_status']=$snapshot===null?'not_archived_local_100k_row_cap':'archived';
        $run = OptimizationRun::create([
            'user_id' => $userId,
            'engine' => self::ENGINE,
            'engine_version' => self::ENGINE_VERSION,
            'model_name' => $curveMode === 'fitted' ? 'fitted:' . ($this->lastModelSummary ?: 'mixed') : 'assumed_exponential_saturation',
            'model_version' => self::ENGINE_VERSION,
            'status' => $payload['status'],
            'input_hash' => $inputHash,
            'inputs' => $options,
            'dataset_fingerprint' => $fingerprint,
            'result' => $payload,
            'source_snapshot'=>$snapshot,
            'snapshot_sha256'=>$snapshot===null?null:hash('sha256',json_encode($snapshot)),
        ]);
        $payload['run_id'] = $run->id;
        $payload['reproducibility'] = [
            'run_id' => $run->id,
            'engine' => self::ENGINE,
            'engine_version' => self::ENGINE_VERSION,
            'input_hash' => $inputHash,
            'archive_status'=>$payload['archive_status'],
            'snapshot_sha256'=>$run->snapshot_sha256,
            'created_at' => $run->created_at?->toIso8601String(),
        ];

        return $payload;
    }

    /** @var array channels with curves from the last run (for sensitivity/stress) */
    private array $lastCurves = [];
    private ?string $lastModelSummary = null;

    /**
     * Fitted curve mode: per-channel daily spend->revenue history, adaptive model
     * selection with validation; channels that fail fitting are EXCLUDED with a
     * reason, never silently mapped onto the assumed curve.
     */
    private function fittedRun(int $userId, $accounts, array $options): OptimizationResultDto
    {
        $this->lastCurves = [];
        $this->lastModelSummary = null;
        $budget = isset($options['budget']) ? (float) $options['budget'] : null;
        $multiplierNote = null;
        if ($budget === null || $budget <= 0) {
            return OptimizationResultDto::abstain('constraints_required', 'A positive budget is required.', max(0.0, (float) $budget));
        }
        $horizon = $options['budget_horizon'] ?? null;
        if (!in_array($horizon, ['daily', 'per_30_days'], true)) {
            return OptimizationResultDto::abstain('constraints_required', 'budget_horizon is required (daily or per_30_days).', $budget);
        }
        $windowDays = AdvertisingOptimizationService::WINDOW_DAYS;
        if ($horizon === 'daily') {
            $budget *= $windowDays;
        }
        $limitsOpt = $options['channel_limits'] ?? [];
        if (!empty($limitsOpt)) {
            $lh = $options['channel_limits_horizon'] ?? null;
            if (!in_array($lh, ['daily', 'per_30_days'], true)) {
                return OptimizationResultDto::abstain('constraints_required', 'channel_limits_horizon (daily or per_30_days) is required whenever channel_limits are given.', $budget);
            }
            if ($lh === 'daily') {
                foreach ($limitsOpt as $ch => $lim) {
                    foreach (['min', 'max'] as $k) {
                        if (isset($lim[$k])) {
                            $limitsOpt[$ch][$k] = (float) $lim[$k] * $windowDays;
                        }
                    }
                }
            }
        }
        $profile = $options['profile'] ?? [];
        if (!empty($profile['budget_cap']) && in_array($profile['budget_cap_horizon'] ?? null, ['daily', 'per_30_days'], true)) {
            $cap = (float) $profile['budget_cap'] * ($profile['budget_cap_horizon'] === 'daily' ? $windowDays : 1);
            if ($budget > $cap + 0.005) {
                return OptimizationResultDto::abstain('exceeds_user_cap', 'The requested budget exceeds your stated budget cap. Nothing was computed.', $budget);
            }
        }

        $end = now()->subDay()->toDateString();
        $start = now()->subDays($windowDays)->toDateString();
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->select('provider', 'date', 'currency', DB::raw('SUM(spend) as spend'), DB::raw('SUM(conversion_value) as revenue'))
            ->groupBy('provider', 'date', 'currency')
            ->orderBy('date')
            ->get();

        // A partial set of accounts is not a complete channel. Mixing account totals
        // with campaign/ad totals double-counts the same delivery. Refuse both.
        $sourceRows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)
            ->get(['connected_ad_account_id', 'date', 'campaign_id', 'ad_set_id', 'ad_id', 'metadata', 'provider']);
        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($sourceRows))return OptimizationResultDto::abstain('incomparable_metric_definitions',$problem,$budget);
        foreach ($accounts as $account) {
            $ar = $sourceRows->where('connected_ad_account_id', $account->id);
            if ($ar->isEmpty()) {
                return OptimizationResultDto::abstain('incomplete_account_coverage', 'A connected account has no rows in the selected window; channel totals would omit it.', $budget);
            }
            foreach ($ar->groupBy(fn($r) => substr((string)$r->date, 0, 10)) as $dayRows) {
                $levels = $dayRows->map(fn($r) => $r->ad_id ? 'ad' : ($r->ad_set_id ? 'ad_set' : ($r->campaign_id ? 'campaign' : 'account')))->unique();
                if ($levels->count() > 1) {
                    return OptimizationResultDto::abstain('overlapping_hierarchy', 'Multiple hierarchy levels exist for the same account/day. They cannot be added without double-counting.', $budget);
                }
            }
        }
        // Account presence must be consistent on every observed channel/day. Missing
        // dates are not zero, and a changing account mix invalidates a pooled fit.
        foreach ($accounts->groupBy('provider') as $provider => $providerAccounts) {
            $pr = $sourceRows->whereIn('connected_ad_account_id', $providerAccounts->pluck('id'));
            foreach ($pr->groupBy(fn($r) => substr((string)$r->date, 0, 10)) as $dayRows) {
                if ($dayRows->pluck('connected_ad_account_id')->unique()->count() !== $providerAccounts->count()) {
                    return OptimizationResultDto::abstain('incomplete_account_coverage', 'The account mix changes across days; missing account-days are not zero-filled.', $budget);
                }
            }
        }

        $byProvider = [];
        foreach ($rows as $r) {
            $p = (string) $r->provider;
            $byProvider[$p]['days'][] = ['date' => substr((string) $r->date, 0, 10), 'spend' => (float) $r->spend, 'revenue' => (float) $r->revenue];
            $byProvider[$p]['currencies'][] = (string) $r->currency;
        }

        $channels = [];
        $excluded = [];
        $modelsUsed = [];
        $limits = $limitsOpt;
        $labels = [
            'meta' => 'Meta Ads (Facebook & Instagram)',
            'google' => 'Google Ads (Search & Performance Max)',
            'tiktok' => 'TikTok Ads (Spark & In-Feed)',
            'snapchat' => 'Snapchat Ads',
        ];
        foreach ($byProvider as $provider => $data) {
            $currencies = array_values(array_unique($data['currencies'] ?? []));
            if (count($currencies) !== 1 || $currencies[0] === '') {
                $excluded[] = "$provider excluded: mixed or unknown currency. No conversion is applied.";
                continue;
            }
            $fit = $this->fitter->fit(array_map(fn($d) => ['spend' => $d['spend'], 'response' => $d['revenue']], $data['days']));
            if ($fit['status'] !== 'ok') {
                $excluded[] = "$provider excluded: " . ($fit['reason'] ?? 'curve fitting failed');
                continue;
            }
            $spend = array_sum(array_map(fn($d) => $d['spend'], $data['days']));
            $revenue = array_sum(array_map(fn($d) => $d['revenue'], $data['days']));
            if ($spend <= 0) {
                $excluded[] = "$provider excluded: zero spend in the window.";
                continue;
            }
            $lim = $limits[$provider] ?? [];
            $channels[] = [
                'channel' => $provider,
                'display_name' => $labels[$provider] ?? $provider,
                'curve' => $fit['curve']->overDays($windowDays),
                'current_spend' => $spend,
                'current_revenue' => $revenue,
                'min' => isset($lim['min']) ? max(0.0, (float) $lim['min']) : 0.0,
                'max' => isset($lim['max']) ? (float) $lim['max'] : $budget,
                'model_meta' => ['validation' => $fit['validation'], 'limits' => $fit['limits']],
                'validation_rmse_rel' => $fit['validation']['validation_rmse_rel'],
            ];
            $modelsUsed[] = $provider . ':' . $fit['model'];
        }

        if (!empty($profile['currency'])) {
            $curSet = [];
            foreach ($byProvider as $data) {
                foreach (array_unique($data['currencies'] ?? []) as $c) {
                    $curSet[$c] = true;
                }
            }
            $curSet = array_keys($curSet);
            if ($curSet !== [] && $curSet !== [strtoupper((string) $profile['currency'])]) {
                return OptimizationResultDto::abstain('currency_mismatch', 'Your business currency differs from the channel currency. No conversion is applied.', $budget, $excluded);
            }
        }
        $allCurrencies = [];
        foreach ($byProvider as $data) {
            $allCurrencies = array_merge($allCurrencies, $data['currencies']);
        }
        if (count(array_unique($allCurrencies)) > 1) {
            return OptimizationResultDto::abstain('mixed_currency', 'Fitted mode cannot combine currencies. No conversion is applied.', $budget, $excluded);
        }
        if (count($channels) < 2) {
            return OptimizationResultDto::abstain(
                'insufficient_data',
                'Fewer than two channels produced a validated fitted curve. ' . implode(' | ', $excluded),
                $budget,
                $excluded
            );
        }

        $this->lastCurves = $channels;
        $this->lastModelSummary = implode(',', $modelsUsed);

        $opt = new GenericCurveOptimizer();
        $result = $opt->optimize($budget, $channels, ['budget_mode' => $options['budget_mode'] ?? 'exact', 'min_marginal_roas' => $options['min_marginal_roas'] ?? null]);
        if ($result->status !== 'ok') {
            return $result;
        }
        $warnings = array_merge($excluded, [
            'Period scenario assumes equal daily spend for 30 days: F(B) = 30 * f(B/30). Missing days are not zero-filled. FITTED curves from observational history: descriptive, not causal proof, and extrapolation beyond the observed spend range is flagged per channel.',
            'Revenue is platform-reported conversion value: not profit, not unique sales; conversions across platforms can overlap.',
            'Nothing is applied automatically. Read-only.',
        ]);
        return new OptimizationResultDto(
            totalBudget: $result->totalBudget,
            currentTotalRevenue: $result->currentTotalRevenue,
            currentBlendedRoas: $result->currentBlendedRoas,
            optimalTotalRevenue: $result->optimalTotalRevenue,
            optimalBlendedRoas: $result->optimalBlendedRoas,
            revenueGainDelta: $result->revenueGainDelta,
            roasGainDelta: $result->roasGainDelta,
            equilibriumLambda: $result->equilibriumLambda,
            channelAllocations: $result->channelAllocations,
            recommendations: [],
            frontierCurve: [],
            sensitivityPoints: [],
            mathematicalModel: $result->mathematicalModel,
            status: 'ok',
            statusMessage: $result->statusMessage,
            warnings: $warnings,
            dataSources: array_map(fn($c) => $c['channel'], $channels),
            certificate: $result->certificate,
            comparison: $result->comparison,
        );
    }

    public function dataQualityBlock(int $userId, $accounts): array
    {
        $windowDays = AdvertisingOptimizationService::WINDOW_DAYS;
        $end = now()->subDay()->toDateString();
        $start = now()->subDays($windowDays)->toDateString();
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->select('provider', 'currency', DB::raw('COUNT(DISTINCT date) as days_with_data'), DB::raw('MAX(date) as latest_date'), DB::raw('SUM(conversion_value) as tv'), DB::raw('COUNT(*) as row_count'), DB::raw('COUNT(DISTINCT date || \'|\' || connected_ad_account_id::text || \'|\' || COALESCE(campaign_id::text, \'account\')) as distinct_entity_days'))
            ->groupBy('provider', 'currency')
            ->get();
        $lastSyncs = \App\Models\SyncRun::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->where('started_at', '>=', $start)->orderByDesc('id')->get()->groupBy('connected_ad_account_id');

        $byProvider = [];
        foreach ($rows as $r) {
            $p = (string) $r->provider;
            $byProvider[$p]['days_with_data'] = max($byProvider[$p]['days_with_data'] ?? 0, (int) $r->days_with_data);
            $byProvider[$p]['latest_date'] = max((string) ($byProvider[$p]['latest_date'] ?? ''), substr((string) $r->latest_date, 0, 10));
            $byProvider[$p]['currencies'][] = (string) $r->currency;
            $byProvider[$p]['has_conversion_value'] = ($byProvider[$p]['has_conversion_value'] ?? false) || ((float) $r->tv > 0);
            $byProvider[$p]['row_count'] = ($byProvider[$p]['row_count'] ?? 0) + (int) $r->row_count;
            $byProvider[$p]['distinct_entity_days'] = ($byProvider[$p]['distinct_entity_days'] ?? 0) + (int) $r->distinct_entity_days;
        }

        $channelScores = [];
        foreach ($byProvider as $provider => $facts) {
            $accIds = $accounts->where('provider', $provider)->pluck('id');
            $failed = 0;
            $lastSynced = null;
            foreach ($accIds as $id) {
                $runs = $lastSyncs->get($id);
                if ($runs) {
                    $failed += $runs->where('status', 'failed')->count();
                }
            }
            $dupes = max(0, ($facts['row_count'] ?? 0) - ($facts['distinct_entity_days'] ?? 0));
            $channelScores[$provider] = $this->quality->scoreChannel([
                'days_with_data' => $facts['days_with_data'] ?? 0,
                'window_days' => $windowDays,
                'latest_date' => $facts['latest_date'] ?: null,
                'today' => now()->toDateString(),
                'currencies' => array_values(array_unique($facts['currencies'] ?? [])),
                'has_conversion_value' => (bool) ($facts['has_conversion_value'] ?? false),
                'duplicate_rows' => $dupes,
                'syncs_failed' => $failed,
                'last_synced_at' => $lastSynced,
            ]);
        }

        return [
            'window' => ['start' => $start, 'end' => $end, 'days' => $windowDays],
            'channels' => $channelScores,
            'portfolio' => $this->quality->portfolio($channelScores),
            'note' => 'Deterministic engineering rubric v' . config('analytics.data_quality.rubric_version') . ', not a calibrated statistical measure. Platform-reported figures; recent days can still change due to reporting lag.',
        ];
    }

    /** Structured recommendation objects (spec 58-59). */
    private function structureRecommendations(array $payload, string $curveMode): array
    {
        if (($payload['status'] ?? null) !== 'ok') {
            return [];
        }
        $out = [];
        $stability = $payload['sensitivity']['stability'] ?? null;
        $highSensitivity = $stability === 'highly_sensitive';
        $incompleteSensitivity=($payload['sensitivity']['status']??null)!=='ok';
        foreach ($payload['channel_allocations'] ?? [] as $al) {
            if (!in_array($al['action'] ?? null, ['SCALE_UP', 'SCALE_DOWN'], true)) {
                continue;
            }
            $id = substr(hash('sha256', json_encode([$al, $payload['total_budget'], $payload['data_quality']['window'] ?? null, $payload['sensitivity'] ?? null, self::ENGINE_VERSION])), 0, 12);
            $out[] = [
                'id' => $id,
                'type' => 'BUDGET_REALLOCATION',
                'entity' => ['platform' => $al['channel'], 'campaign_id' => null],
                'severity' => ($highSensitivity||$incompleteSensitivity) ? 'LOW' : 'MEDIUM',
                'observed' => ['metric' => 'spend', 'value' => $al['current_spend'] ?? null],
                'baseline' => ['metric' => 'spend_same_budget', 'value' => $al['baseline_spend_same_budget'] ?? null],
                'scenario' => ['metric' => 'spend', 'value' => $al['optimal_spend'] ?? null],
                'comparison_basis' => 'scenario_vs_same_budget_mix',
                'change_percent' => $al['delta_vs_baseline_percentage'] ?? null,
                'change_vs_actual_percent' => $al['delta_spend_percentage'] ?? null,
                'model' => [
                    'name' => $curveMode === 'fitted' ? ('fitted:' . ($al['model'] ?? 'unknown')) : 'assumed_exponential_saturation',
                    'version' => self::ENGINE_VERSION,
                ],
                'confidence' => null,
                'confidence_reason' => $curveMode === 'fitted'
                    ? 'No calibrated confidence for allocation shifts; stress ranges use held-out relative RMSE, not a confidence level.'
                    : 'The curve is an explicit assumption, so no data-derived confidence exists.',
                'stability' => $stability,
                'action' => ['type' => 'SIMULATION_ONLY'],
                'title' => sprintf('Same-budget mix scenario: %s share of %s', $al['action'] === 'SCALE_UP' ? 'raise' : 'hold back', $al['display_name'] ?? $al['channel']),
                'description' => $incompleteSensitivity?'Some sensitivity solves were infeasible or uncertified. Stability is unknown; inspect these limits before reviewing any reallocation.':($highSensitivity
                    ? 'The model identifies a potential reallocation opportunity, but the result is highly sensitive to model assumptions (see sensitivity). Review the scenario before any decision.'
                    : 'Scenario under the stated curve; relative to your current channel mix scaled to the same total spend.'),
                'evidence' => [
                    'optimal_spend' => $al['optimal_spend'] ?? null,
                    'baseline_spend_same_budget' => $al['baseline_spend_same_budget'] ?? null,
                    'marginal_roas_current' => $al['marginal_roas_current'] ?? null,
                    'marginal_roas_optimal' => $al['marginal_roas_optimal'] ?? null,
                ],
            ];
        }
        return $out;
    }

    private function datasetFingerprint(int $userId, $accounts): array
    {
        // Hash ordered source rows, not aggregates: redistributing daily revenue
        // without changing its total must still change the fingerprint.
        $context = hash_init('sha256');
        $n = 0;
        $rows = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))
            ->whereDate('date', '>=', now()->subDays(AdvertisingOptimizationService::WINDOW_DAYS)->toDateString())
            ->whereDate('date', '<=', now()->subDay()->toDateString())
            ->orderBy('id')->cursor();
        foreach ($rows as $row) {
            hash_update($context, json_encode($row->getAttributes(), JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $n++;
        }
        return [
            'rows' => $n, 'sha256' => hash_final($context),
            'window_end' => now()->subDay()->toDateString(),
            'engine_version' => self::ENGINE_VERSION,
            'configuration' => ['analytics' => config('analytics'), 'or' => config('or')],
            'accounts' => $accounts->sortBy('id')->values()->toArray(),
        ];
    }
}
