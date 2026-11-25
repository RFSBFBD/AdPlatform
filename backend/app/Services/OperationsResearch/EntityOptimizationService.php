<?php

namespace App\Services\OperationsResearch;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\ConnectedAdAccount;
use App\Models\PerformanceMetric;
use App\Models\SyncRun;
use App\Services\Analytics\DataQualityEngine;
use App\Services\Analytics\ResponseCurveFitter;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
use Illuminate\Support\Facades\DB;

/** One chosen hierarchy level, never sum parent and child reports. Fitted scenarios only. */
final class EntityOptimizationService
{
    public function run(int $userId, array $options): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(function () use ($userId, $options) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }

return $this->compute($userId, $options);
            });
        }

        return $this->compute($userId, $options);
    }

    private function compute(int $userId, array $options): array
    {
        if($originalProblem=(new \App\Services\Analytics\OriginalSourceAnalysisGate)->problem($userId,$options['provider']??null)){return $this->no('unqualified_original_source',$originalProblem);}
        $level = $options['entity_level'];
        $objective = $options['objective'];
        $response = in_array($objective,['MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS'],true)?($objective==='MAXIMIZE_LEADS'?'typed_leads':'typed_qualified_leads'):(in_array($objective, ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'TARGET_CPA'], true) ? 'conversions' : 'conversion_value');
        $budget = (float) $options['budget'];
        if((float)($options['minimum_total_spend']??0)>$budget)return $this->no('infeasible','Minimum total spend exceeds the budget cap.');
        $accounts = ConnectedAdAccount::where('user_id', $userId)->when($options['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))->get(['id', 'provider', 'currency', 'timezone']);
        $definition=null;
        if(in_array($objective,['MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS'],true)){
            $definition=\App\Models\ConversionDefinition::where('id',$options['conversion_definition_id'])->whereIn('connected_ad_account_id',$accounts->pluck('id'))->first();
            if(!$definition||$definition->kind!==($objective==='MAXIMIZE_LEADS'?'lead':'qualified_lead'))return $this->no('invalid_conversion_definition','An owned explicit definition of the requested lead kind in the selected provider scope is required. Generic conversions, leads and qualified leads are not interchangeable.');
            $accounts=$accounts->where('id',$definition->connected_ad_account_id)->values();
        }
        if ($accounts->isEmpty()) {
            return $this->no('insufficient_data', 'No connected accounts in the selected scope.');
        }
        $currencies = $accounts->pluck('currency')->unique();
        $timezones = $accounts->pluck('timezone')->unique();
        if ($currencies->count() !== 1 || ! $currencies[0] || $timezones->count() !== 1 || ! $timezones[0]) {
            return $this->no('incomparable_scope', 'A single known currency and reporting timezone is required for an entity comparison. No conversion or timezone shift is assumed.');
        }
        if(!in_array($timezones[0],\DateTimeZone::listIdentifiers(),true))return $this->no('incomparable_scope','A verified IANA reporting timezone is required.');
        $localNow=now()->setTimezone($timezones[0]);
        $end=$localNow->copy()->subDay()->toDateString();
        $start=$localNow->copy()->subDays(30)->toDateString();
        $profile = BusinessProfile::where('user_id', $userId)->first();
        if ($profile && $profile->currency && $profile->currency !== $currencies[0]) {
            return $this->no('currency_mismatch', 'Business profile currency differs from source entities.');
        }
        if ($profile?->budget_cap) {
            $cap = $profile->budget_cap * ($profile->budget_cap_horizon === 'daily' ? 30 : 1);
            if (! in_array($profile->budget_cap_horizon, ['daily', 'per_30_days'], true) || $budget > $cap) {
                return $this->no('exceeds_user_cap', 'Budget cap horizon must be valid and the requested 30-day budget must stay within it.');
            }
        }
        $q = PerformanceMetric::whereIn('connected_ad_account_id', $accounts->pluck('id'))->whereBetween('date', [$start, $end]);
        // Selecting a level excludes parents/children by construction, not by a potentially ambiguous COALESCE id.
        $q = match ($level) {
            'campaign' => $q->whereNotNull('campaign_id')->whereNull('ad_set_id')->whereNull('ad_id'),'ad_set' => $q->whereNotNull('ad_set_id')->whereNull('ad_id'),'ad' => $q->whereNotNull('ad_id')
        };
        $rows = $q->orderBy('date')->limit(30001)->get();
        if ($rows->count() > 30000) {
            return $this->no('local_limit', 'Selected entity history exceeds the local 30000-row analysis cap. Narrow the provider scope.');
        }
        if($definition){
            $typed=\App\Models\TypedConversionDaily::where('conversion_definition_id',$definition->id)->where('entity_level',$level)->whereBetween('date',[$start,$end])->limit(30001)->get()->keyBy(fn($r)=>$r->entity_id.'|'.$r->date->toDateString());
            if($typed->count()>30000)return $this->no('local_limit','Typed lead source exceeds the30000row local cap.');
            $spendKeys=$rows->map(fn($r)=>$r->{$level.'_id'}.'|'.$r->date->toDateString())->all();if(array_diff($typed->keys()->all(),$spendKeys))return $this->no('incomplete_spend_history','Typed lead entities/dates without matching selected-level spend cannot silently disappear from the population.');
            foreach($rows as$row){$typedRow=$typed->get($row->{$level.'_id'}.'|'.$row->date->toDateString());if(!$typedRow||$typedRow->currency!==$accounts[0]->currency||$typedRow->timezone!==$accounts[0]->timezone)return $this->no('incomplete_lead_history','Every spend observation needs the explicitly selected typed lead definition, date, currency and timezone. No generic conversion substitution.');
                $row->setAttribute($response,$typedRow->count);$row->setAttribute('typed_conversion_source',$typedRow->getAttributes());$row->setAttribute('conversion_definition_snapshot',$definition->getAttributes());
            }
        }
        // Source FK existence alone is not tenant ownership: validate the entire chosen parent chain.
        $campaignMap = Campaign::whereIn('id', $rows->pluck('campaign_id')->filter()->unique())->get()->keyBy('id');
        $setMap = AdSet::whereIn('id', $rows->pluck('ad_set_id')->filter()->unique())->get()->keyBy('id');
        $adMap = Ad::whereIn('id', $rows->pluck('ad_id')->filter()->unique())->get()->keyBy('id');
        $accountMap = $accounts->keyBy('id');
        foreach ($rows as $row) {
            $account = $accountMap[$row->connected_ad_account_id];
            $campaign = $campaignMap[$row->campaign_id] ?? null;
            if (! $campaign || $campaign->connected_ad_account_id !== $row->connected_ad_account_id || $campaign->provider !== $account->provider || $row->provider !== $account->provider) {
                return $this->no('invalid_entity_ownership', 'Metric campaign/account/provider linkage is inconsistent.');
            }
            if ($level !== 'campaign') {
                $set = $setMap[$row->ad_set_id] ?? null;
                if (! $set || $set->campaign_id !== $campaign->id) {
                    return $this->no('invalid_entity_ownership', 'Ad-set parent linkage is inconsistent.');
                }
            }
            if ($level === 'ad') {
                $ad = $adMap[$row->ad_id] ?? null;
                if (! $ad || $ad->ad_set_id !== $set->id) {
                    return $this->no('invalid_entity_ownership', 'Ad parent linkage is inconsistent.');
                }
            }
        }
        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->problem($rows,!$definition))return $this->no('incomparable_metric_definitions',$problem);
        $idColumn = $level.'_id';
        $groups = $rows->groupBy(fn ($r) => $r->connected_ad_account_id.':'.$r->{$idColumn});
        if ($groups->isEmpty() || $groups->count() > 100) {
            return $this->no('insufficient_data', 'Need 1-100 entities at the chosen report level. Account-only providers cannot supply campaign/ad data.');
        }
        $limits = $options['entity_limits'] ?? [];
        $channels = [];
        $fits = [];
        $excluded = [];
        foreach ($groups as $key => $history) {
            foreach ($history as $row) {
                foreach (['spend', $response] as $metric) {
                    if (! is_numeric($row->{$metric}) || ! is_finite((float) $row->{$metric}) || $row->{$metric} < 0) {
                        return $this->no('invalid_data', 'Negative, missing or nonfinite source metrics prevent this comparison.');
                    }
                }
            }
            $dates = $history->map(fn ($r) => $r->date->toDateString());
            if ($history->count() !== 30 || $dates->unique()->count() !== 30 || $history->pluck('currency')->unique()->count() !== 1 || $history->first()->currency !== $currencies[0]) {
                return $this->no('incomplete_entity_history', 'Every selected entity needs 30 distinct complete-day observations in one shared currency. No zero fill or partial subset is used.');
            }
            $quality = app(DataQualityEngine::class)->scoreChannel(['days_with_data' => 30, 'window_days' => 30, 'latest_date' => $end, 'today' => $localNow->toDateString(), 'currencies' => [$currencies[0]], 'has_conversion_value' => $history->sum($response) > 0, 'duplicate_rows' => 0, 'syncs_failed' => SyncRun::where('connected_ad_account_id', $history->first()->connected_ad_account_id)->where('status', 'failed')->where('started_at', '>=', $start)->count()]);
            if ($quality['score'] < (float) config('analytics.data_quality.optimize_threshold')) {
                return $this->no('insufficient_data_quality', 'Entity data quality did not pass the configured optimization gate.', ['entity' => $key, 'quality' => $quality]);
            }
            $fit = app(ResponseCurveFitter::class)->fit($history->map(fn ($r) => ['spend' => (float) $r->spend, 'response' => (float) $r->{$response}])->all(),($options['solver']??'continuous')==='discrete_milp');
            if ($fit['status'] !== 'ok') {
                return $this->no('no_valid_model', 'At least one selected entity failed response-fit validation. No silent exclusion from the budget pool.', ['entity' => $key, 'fit' => $fit]);
            }
            $min = (float) ($limits[$key]['min'] ?? 0);
            $max = (float) ($limits[$key]['max'] ?? $budget);
            $channels[] = ['channel' => $key, 'display_name' => $level.' '.$key, 'curve' => $fit['curve']->overDays(30), 'validation_rmse_rel'=>$fit['validation']['validation_rmse_rel'], 'current_spend' => (float) $history->sum('spend'), 'current_revenue' => (float) $history->sum($response), 'min' => $min, 'max' => $max];
            $fits[$key] = ['family' => $fit['model'],'candidates'=>$fit['candidates'],'selection_note'=>'Selected among validated monotone concave candidates by chronological holdout error plus complexity and instability penalties. Score is an engineering selection criterion, not confidence. Daily data remain observational.', 'validation' => $fit['validation'], 'account_id' => $history->first()->connected_ad_account_id, 'entity_id' => $history->first()->{$idColumn}, 'provider' => $history->first()->provider,'entity_name' => match ($level) {'campaign' => $campaignMap[$history->first()->campaign_id]->name, 'ad_set' => $setMap[$history->first()->ad_set_id]->name, 'ad' => $adMap[$history->first()->ad_id]->name},'conversion_definition'=>$definition?->only(['id','external_key','name','kind','counting_method','attribution_model','click_window_days','view_window_days','source_system']),'provenance'=>$definition?'user_imported_aggregate_not_provider_verified':'provider_reported'];
        }
        // A chosen provider's connected account with no chosen-level history cannot disappear from the allocation population.
        if ($rows->pluck('connected_ad_account_id')->unique()->count() !== $accounts->count()) {
            return $this->no('incomplete_account_coverage', 'A selected connected account has no reports at this entity level. Narrow the provider scope or ingest the missing level.');
        }
        if (array_diff(array_keys($limits), array_keys($groups->all()))) {
            return $this->no('invalid_limits', 'Entity limits refer to an entity outside the selected scope.');
        }
        if(($options['solver']??'continuous')==='discrete_milp'){
            if(!in_array($objective,['MAXIMIZE_REVENUE','MAXIMIZE_CONVERSIONS','MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS','TARGET_REVENUE','TARGET_CPA','TARGET_ROAS'],true)){return $this->no('unsupported_objective','Discrete MILP currently supports explicit value/conversion/typed-lead maximization or minimum spend for a value target; unconstrained ratio-maximization objectives are not silently substituted.');}
            $discrete=app(DiscreteBudgetOptimizer::class)->optimize($channels,$budget,(float)$options['budget_step'],(int)$options['maximum_active'],(float)($options['minimum_total_spend']??0),$objective==='TARGET_REVENUE'?(float)$options['target_reported_value']:null,in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?($objective==='TARGET_CPA'?1/(float)$options['target_ratio']:(float)$options['target_ratio']):null);
            if($discrete['status']!=='ok')return $this->no($discrete['status'],$discrete['reason']);
            $alloc=[];foreach($channels as$ch){$x=$discrete['allocation'][$ch['channel']];$alloc[]=['entity_key'=>$ch['channel'],'source'=>$fits[$ch['channel']],'actual_spend'=>$ch['current_spend'],'actual_response'=>$ch['current_revenue'],'scenario_spend'=>$x,'scenario_response'=>$ch['curve']->revenueAt($x),'outside_observed_support'=>$x>$ch['curve']->supportMaxSpend()||$x<$ch['curve']->supportMinSpend()];}
            $payload=['status'=>'ok','type'=>'SCENARIO','solver'=>'discrete_milp','objective'=>$objective,'response_metric'=>$response,'entity_level'=>$level,'currency'=>$currencies[0],'timezone'=>$timezones[0],'window'=>['start'=>$start,'end'=>$end,'days'=>30],'period_days'=>30,'total_budget'=>$budget,'scenario_total_spend'=>$discrete['total_spend'],'scenario_response'=>$discrete['modeled_response'],'actual_response'=>array_sum(array_column($channels,'current_revenue')),'ratio_objective_value'=>in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?($objective==='TARGET_CPA'?$discrete['total_spend']/$discrete['modeled_response']:$discrete['modeled_response']/$discrete['total_spend']):null,'target_ratio'=>$options['target_ratio']??null,'ratio_search'=>null,'allocations'=>$alloc,'certificate'=>$discrete['certificate'],'active_entities'=>$discrete['active_entities'],'target_reported_value'=>$objective==='TARGET_REVENUE'?(float)$options['target_reported_value']:null,'limits'=>[$discrete['note'],'Fitted observations do not establish causal incremental response. Equal daily spend assumed over30days. Reported conversions need not be unique people.']];
            return app(EntityOptimizationArchive::class)->save($userId,$options,$payload,$channels,$rows->map(fn($r)=>$r->getAttributes())->all(),$accounts->toArray());
        }
        if($objective==='TARGET_REVENUE'){
            $target=(new TargetValueOptimizer)->optimize($channels,(float)$options['target_reported_value'],$budget,(float)($options['minimum_total_spend']??0));
            if($target['status']!=='ok')return $this->no($target['status'],$target['reason']);
            $chosen=array_column($target['channels'],'spend','channel');$alloc=[];foreach($channels as$ch){$x=$chosen[$ch['channel']];$alloc[]=['entity_key'=>$ch['channel'],'source'=>$fits[$ch['channel']],'actual_spend'=>$ch['current_spend'],'actual_response'=>$ch['current_revenue'],'scenario_spend'=>$x,'scenario_response'=>$ch['curve']->revenueAt($x),'outside_observed_support'=>$x<$ch['curve']->supportMinSpend()||$x>$ch['curve']->supportMaxSpend()];}
            $payload=['status'=>'ok','type'=>'SCENARIO','objective'=>$objective,'response_metric'=>$response,'entity_level'=>$level,'currency'=>$currencies[0],'timezone'=>$timezones[0],'window'=>['start'=>$start,'end'=>$end,'days'=>30],'period_days'=>30,'total_budget'=>$budget,'scenario_total_spend'=>$target['total_spend'],'scenario_response'=>$target['modeled_conversion_value'],'actual_response'=>array_sum(array_column($channels,'current_revenue')),'target_reported_value'=>(float)$options['target_reported_value'],'target_search'=>['budget_lower_bound'=>$target['budget_lower_bound'],'budget_gap'=>$target['budget_gap'],'iterations'=>$target['iterations']],'ratio_objective_value'=>in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?($objective==='TARGET_CPA'?$discrete['total_spend']/$discrete['modeled_response']:$discrete['modeled_response']/$discrete['total_spend']):null,'target_ratio'=>$options['target_ratio']??null,'ratio_search'=>null,'allocations'=>$alloc,'certificate'=>$target['inner_certificate'],'limits'=>[$target['note'],'Minimum spend for a modeled reported-value target, not guaranteed revenue. All entity minima mandatory. Source observations do not establish causal lift.']];
            return app(EntityOptimizationArchive::class)->save($userId,$options,$payload,$channels,$rows->map(fn($r)=>$r->getAttributes())->all(),$accounts->toArray());
        }
        $ratioSearch = null;
        $ratioValue = null;
        $isRatio = in_array($objective, ['MAXIMIZE_ROAS', 'MINIMIZE_CPA', 'TARGET_CPA', 'TARGET_ROAS'], true);
        if ($isRatio) {
            $ratio = in_array($objective, ['TARGET_CPA', 'TARGET_ROAS'], true)
              ? app(PortfolioRatioOptimizer::class)->target($channels, (float) ($options['minimum_total_spend'] ?? 0), $budget, $objective === 'TARGET_CPA' ? 1 / (float) $options['target_ratio'] : (float) $options['target_ratio'])
              : app(PortfolioRatioOptimizer::class)->optimize($channels, (float) ($options['minimum_total_spend'] ?? 0), $budget);
            if ($ratio['status'] !== 'ok') {
                return $this->no($ratio['status'], $ratio['reason']);
            }
            $result = $ratio['result'];
            $ratioSearch = $ratio['search'];
            $ratioValue = in_array($objective, ['MINIMIZE_CPA', 'TARGET_CPA'], true) ? 1 / $ratio['ratio'] : $ratio['ratio'];
        } else {
            $result = (new GenericCurveOptimizer)->optimize($budget, $channels)->toArray();
        }

        if ($result['status'] !== 'ok') {
            return $this->no($result['status'], $result['status_message'] ?? 'Solver abstained.');
        }
        $alloc = [];
        $scenarioResponse = 0.0;
        $scenarioSpend = 0.0;
        $curveMap = collect($channels)->keyBy('channel');
        foreach ($result['channel_allocations'] as $a) {
            $x = $a['solver_spend'];
            $y = $curveMap[$a['channel']]['curve']->revenueAt($x);
            $scenarioResponse += $y;
            $scenarioSpend += $x;
            $alloc[] = ['entity_key' => $a['channel'], 'source' => $fits[$a['channel']], 'actual_spend' => $a['current_spend'], 'actual_response' => $a['current_revenue'], 'scenario_spend' => $x, 'scenario_response' => $y, 'same_budget_baseline_spend' => $a['baseline_spend_same_budget'], 'same_budget_baseline_response' => $a['baseline_revenue_same_budget'], 'outside_observed_support' => $a['extrapolates_beyond_support']];
        }

        $payload=['status' => 'ok', 'type' => 'SCENARIO', 'objective' => $objective, 'response_metric' => $response, 'entity_level' => $level, 'currency' => $currencies[0], 'timezone' => $timezones[0], 'window' => ['start' => $start, 'end' => $end, 'days' => 30], 'period_days' => 30, 'total_budget' => $budget, 'scenario_total_spend' => $scenarioSpend, 'ratio_objective_value' => $ratioValue, 'ratio_search' => $ratioSearch, 'target_ratio' => $options['target_ratio'] ?? null, 'actual_response' => $result['current_total_revenue'], 'scenario_response' => $scenarioResponse, 'allocations' => $alloc, 'certificate' => $result['solver_certificate'], 'limits' => ['Entity key is connected_account_id:internal_entity_id. Only the selected level enters the pool.', 'Fitted on observational reported/imported data under the stated source definition, not causal incremental response. Reported conversions may be fractional, not unique people.', 'Equal daily spend over 30 days is assumed. Missing entity-days or failed fits stop the whole comparison.', 'Per-entity constraints are scenario bounds only, no campaign execution. Fitted curves and source rows are archived with a checksum, not a tamper-proof external ledger.']];
        return app(EntityOptimizationArchive::class)->save($userId,$options,$payload,$channels,$rows->map(fn($r)=>$r->getAttributes())->all(),$accounts->toArray());
    }

    private function no(string $status,string $reason,array $details = []): array
    {
        return ['status' => $status, 'reason' => $reason, 'allocations' => [], 'details' => $details];
    }
}
