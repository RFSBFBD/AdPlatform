<?php

namespace App\Services\OperationsResearch;

use App\Models\OptimizationRun;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;

final class EntityOptimizationArchive
{
    public const VERSION = 'entity-2.7';

    public function save(int $userId, array $options, array $result, array $channels, array $rows, array $accounts): array
    {
        $result['comparison']=(new EntityScenarioComparison)->build($channels,$options,$result);
        $scenarioEngine=new \App\Services\Analytics\ScenarioEngine;
        $chosen=array_column($result['allocations'],'scenario_spend','entity_key');
        $result['stress']=$scenarioEngine->stressRange($channels,$chosen);
        $result['response_profiles']=(new \App\Services\Analytics\EntityResponseProfiles)->build($channels,$chosen);
        if(isset($options['robust_bounds'])){
            $result['robust_scenario']=$options['objective']!=='MAXIMIZE_REVENUE'
                ?['status'=>'unsupported_objective','reason'=>'Entity box-robust scenario requires explicit MAXIMIZE_REVENUE. No conversion, ratio or target objective is substituted.']
                :(($options['solver']??'continuous')==='discrete_milp'
                    ?(new DiscreteBoxRobustOptimizer)->optimize($channels,(float)$options['budget'],$options['robust_bounds'],(float)$options['budget_step'],(int)$options['maximum_active'],(float)($options['minimum_total_spend']??0))
                    :(new BoxRobustOptimizer)->optimize($channels,(float)$options['budget'],$options['robust_bounds']));
        }

        if(($options['solver']??'continuous')==='continuous'&&in_array($options['objective'],['MAXIMIZE_REVENUE','MAXIMIZE_CONVERSIONS','MAXIMIZE_LEADS','MAXIMIZE_QUALIFIED_LEADS'],true)){
            $result['sensitivity']=$scenarioEngine->sensitivity($channels,(float)$options['budget'],function($chs,$budget){$r=(new GenericCurveOptimizer)->optimize($budget,$chs)->toArray();return $r['status']==='ok'?array_column($r['channel_allocations'],'solver_spend','channel'):null;});
        }elseif(($options['solver']??'continuous')==='discrete_milp'){
            $result['sensitivity']=(new DiscreteEntitySensitivity)->analyze($channels,$options);
        }else{$result['sensitivity']=(new ContinuousTargetSensitivity)->analyze($channels,$options);}

        $s = ['schema_version' => 1, 'engine_version' => self::VERSION, 'channels' => array_map(function ($c) {
            $curve = $c['curve'];
            $c['curve'] = ['name' => $curve->name(), 'params' => $curve->parameters(), 'support_min' => $curve->supportMinSpend(), 'support_max' => $curve->supportMaxSpend()];

            return $c;
        }, $channels), 'source_rows' => $rows, 'accounts' => $accounts, 'configuration' => ['analytics' => config('analytics'), 'or' => config('or')]];
        $hash = hash('sha256', json_encode($s));
        $r = OptimizationRun::create(['user_id' => $userId, 'engine' => 'entity', 'engine_version' => self::VERSION, 'model_name' => 'fitted_entity_portfolio', 'model_version' => self::VERSION, 'status' => $result['status'], 'input_hash' => hash('sha256', json_encode([$options, $hash])), 'inputs' => $options, 'dataset_fingerprint' => ['row_count' => count($rows)], 'result' => $result, 'source_snapshot' => $s, 'snapshot_sha256' => $hash]);

        return $result + ['run_id' => $r->id, 'archive_status' => 'archived', 'snapshot_sha256' => $hash];
    }

    public function replay(OptimizationRun $r,string $scenario='base'): array
    {
        if($r->integrityStatus()==='mismatch')return ['status'=>'integrity_mismatch','reason'=>'Stored inputs or result differ from their creation checksum.'];
        if (!in_array($r->engine_version,['entity-2.0','entity-2.1','entity-2.2','entity-2.3','entity-2.4','entity-2.5','entity-2.6',self::VERSION],true)) {
            return ['status' => 'version_mismatch', 'reason' => 'Entity replay requires matching engine version.'];
        }
        $s = $r->source_snapshot;
        if (! $s || ! hash_equals($r->snapshot_sha256 ?? '', hash('sha256', json_encode($s)))) {
            return ['status' => 'checksum_mismatch', 'reason' => 'Snapshot checksum mismatch.'];
        }
        if($problem=(new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($r))return ['status'=>'not_computable','reason'=>$problem];
        $channels = [];
        foreach ($s['channels'] as $ch) {
            $c = $ch['curve'];
            $p = $c['params'];
            $ch['curve'] = match ($c['name']) {
                'quadratic_spline'=>ResponseCurve::quadraticSpline($p['a'],$p['b'],$p['knot'],$c['support_max'],$c['support_min']),
                'piecewise'=>ResponseCurve::piecewise($p['a'],$p['b'],$p['knot'],$c['support_max'],$c['support_min']),
                'linear' => ResponseCurve::linear($p['a'], $c['support_max'], $c['support_min']),'log' => ResponseCurve::log($p['a'], $p['b'], $c['support_max'], $c['support_min']),'power' => ResponseCurve::power($p['a'], $p['b'], $c['support_max'], $c['support_min']),'saturation' => ResponseCurve::saturation($p['a'], $p['b'], $c['support_max'], $c['support_min'])
            };
            $channels[] = $ch;
        }
        $o = $r->inputs;
        $objective = $o['objective'];
        if($scenario==='robust'){
            $original=$r->result['robust_scenario']??null;
            if($o['objective']!=='MAXIMIZE_REVENUE'||!isset($o['robust_bounds'])||($original['status']??null)!=='ok')return ['status'=>'not_computable','reason'=>'This run has no successful archived box-robust value scenario. No nominal scenario substituted.'];
            $oldAnalytics=config('analytics');$oldOr=config('or');
            try{config(['analytics'=>$s['configuration']['analytics'],'or'=>$s['configuration']['or']]);
                $robust=($o['solver']??'continuous')==='discrete_milp'
                    ?(new DiscreteBoxRobustOptimizer)->optimize($channels,(float)$o['budget'],$o['robust_bounds'],(float)$o['budget_step'],(int)$o['maximum_active'],(float)($o['minimum_total_spend']??0))
                    :(new BoxRobustOptimizer)->optimize($channels,(float)$o['budget'],$o['robust_bounds']);
            }finally{config(['analytics'=>$oldAnalytics,'or'=>$oldOr]);}
            if($robust['status']!=='ok')return ['status'=>'replay_failed','reason'=>$robust['reason']??'Robust replay failed its certificate.'];
            $before=array_column($original['channels'],'spend','channel');$after=array_column($robust['channels'],'spend','channel');$delta=0.;foreach($before as$key=>$x)$delta=max($delta,abs($x-($after[$key]??INF)));$valueDelta=abs($original['worst_case_conversion_value']-$robust['worst_case_conversion_value']);
            return ['status'=>'ok','type'=>'REPLAY','scenario'=>'robust','run_id'=>$r->id,'matches_archived_robust_result'=>array_keys($before)===array_keys($after)&&$delta<=.01&&$valueDelta<=.01,'max_allocation_delta'=>$delta,'worst_value_delta'=>$valueDelta,'result'=>$robust,'snapshot_sha256'=>$r->snapshot_sha256,'note'=>'Re-executed archived independent-scale box bounds, fitted curves and constraints. No current metrics, provider requests or refitting. Current installed solver/runtime may alter ties; matching is not uniqueness. Checksum is not tamper-proof against database administrators.'];
        }

        $old = config('or.maintain_band_percent');
        try {
            config(['or.maintain_band_percent' => $s['configuration']['or']['maintain_band_percent']]);
            if(($o['solver']??'continuous')==='discrete_milp'){
                $discrete=(new DiscreteBudgetOptimizer)->optimize($channels,(float)$o['budget'],(float)$o['budget_step'],(int)$o['maximum_active'],(float)($o['minimum_total_spend']??0),$objective==='TARGET_REVENUE'?(float)$o['target_reported_value']:null,in_array($objective,['TARGET_CPA','TARGET_ROAS'],true)?($objective==='TARGET_CPA'?1/(float)$o['target_ratio']:(float)$o['target_ratio']):null);
                if($discrete['status']!=='ok')return ['status'=>'replay_failed','reason'=>$discrete['reason']];
                $actual=$discrete['allocation'];$original=array_column($r->result['allocations'],'scenario_spend','entity_key');$delta=0.;foreach($original as$key=>$x)$delta=max($delta,abs($x-($actual[$key]??INF)));
                return ['status'=>'ok','type'=>'REPLAY','run_id'=>$r->id,'matches_archived_base_result'=>array_keys($actual)===array_keys($original)&&$delta<=.01,'max_allocation_delta'=>$delta,'allocation'=>$actual,'note'=>'Replayed archived fitted curves and discrete choices with current installed HiGHS. Solver versions can change ties; original certificate is retained. Menu optimum only, no continuous error bound.'];
            }
            if($objective==='TARGET_REVENUE'){
                $target=(new TargetValueOptimizer)->optimize($channels,(float)$o['target_reported_value'],(float)$o['budget'],(float)($o['minimum_total_spend']??0));if($target['status']!=='ok')return ['status'=>'replay_failed','reason'=>$target['reason']];$out=['status'=>'ok','channel_allocations'=>array_map(fn($a)=>['channel'=>$a['channel'],'solver_spend'=>$a['spend']],$target['channels'])];
            } elseif (in_array($objective, ['TARGET_CPA', 'TARGET_ROAS'], true)) {
                $outer = (new PortfolioRatioOptimizer)->target($channels, (float) $o['minimum_total_spend'], (float) $o['budget'], $objective === 'TARGET_CPA' ? 1 / (float) $o['target_ratio'] : (float) $o['target_ratio']);
                $out = $outer['result'] ?? null;
            } elseif (in_array($objective, ['MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true)) {
                $outer = (new PortfolioRatioOptimizer)->optimize($channels, (float) $o['minimum_total_spend'], (float) $o['budget']);
                $out = $outer['result'] ?? null;
            } else {
                $out = (new GenericCurveOptimizer)->optimize((float) $o['budget'], $channels)->toArray();
            }
        } finally {
            config(['or.maintain_band_percent' => $old]);
        }
        if (! $out || $out['status'] !== 'ok') {
            return ['status' => 'replay_failed', 'reason' => 'Archived solver did not return a certified inner allocation.'];
        }
        $actual = array_column($out['channel_allocations'], 'solver_spend', 'channel');
        $original = array_column($r->result['allocations'], 'scenario_spend', 'entity_key');
        $delta = 0.;
        foreach ($original as $key => $x) {
            $delta = max($delta, abs($x - ($actual[$key] ?? INF)));
        }

        return ['status' => 'ok', 'type' => 'REPLAY', 'run_id' => $r->id, 'matches_archived_base_result' => array_keys($actual) === array_keys($original) && $delta <= .01, 'max_allocation_delta' => $delta, 'allocation' => $actual, 'snapshot_sha256' => $r->snapshot_sha256, 'note' => 'Archived entity curves, objective, source rows and configuration. No live metrics, refitting or provider requests. Checksum detects accidental changes, not privileged tampering. Ratio outer search remains numerical.'];
    }
}
