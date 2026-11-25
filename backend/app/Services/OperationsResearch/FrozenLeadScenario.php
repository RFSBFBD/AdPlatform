<?php
namespace App\Services\OperationsResearch;

use App\Models\OptimizationRun;
use App\Services\Analytics\Curves\ResponseCurve;

/** Read-only custom comparison on one sealed, already successful typed-lead archive. */
final class FrozenLeadScenario
{
    public function evaluate(OptimizationRun $run, array $allocation): array
    {
        if ($run->engine !== 'entity' || $run->status !== 'ok' || ($run->inputs['objective'] ?? null) !== 'MAXIMIZE_LEADS' || ($run->inputs['entity_level'] ?? null) !== 'campaign' || ($run->inputs['solver'] ?? 'continuous') !== 'continuous') return $this->no('A successful continuous campaign typed-lead archive is required.');
        if ($run->integrityStatus() !== 'matches') return $this->no('Archive input/result checksum failed or is legacy unsealed.');
        $snapshot = $run->source_snapshot;
        if (!$snapshot || !hash_equals($run->snapshot_sha256 ?? '', hash('sha256', json_encode($snapshot)))) return $this->no('Source snapshot checksum failed.');
        if ($problem = (new \App\Services\Analytics\MetaDefinitionTransitionGuard)->archiveProblem($run)) return $this->no($problem);
        if ($run->engine_version !== EntityOptimizationArchive::VERSION) return $this->no('Matching entity engine version required.');
        $keys = array_column($snapshot['channels'] ?? [], 'channel');
        if (count($keys) < 1 || array_diff($keys, array_keys($allocation)) || array_diff(array_keys($allocation), $keys)) return $this->no('Exactly one amount per archived campaign is required.');
        if (count($snapshot['accounts'] ?? []) !== 1 || empty($run->inputs['conversion_definition_id']) || ($run->result['response_metric'] ?? null) !== 'typed_leads') return $this->no('Single-account typed-lead source required.');
        foreach ($allocation as $value) if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) return $this->no('Every amount must be finite and nonnegative.');
        $channels = [];
        foreach ($snapshot['channels'] as $ch) {
            $c = $ch['curve']; $p = $c['params'];
            $ch['curve'] = match ($c['name']) {
                'quadratic_spline' => ResponseCurve::quadraticSpline($p['a'], $p['b'], $p['knot'], $c['support_max'], $c['support_min']),
                'piecewise' => ResponseCurve::piecewise($p['a'], $p['b'], $p['knot'], $c['support_max'], $c['support_min']),
                'linear' => ResponseCurve::linear($p['a'], $c['support_max'], $c['support_min']),
                'log' => ResponseCurve::log($p['a'], $p['b'], $c['support_max'], $c['support_min']),
                'power' => ResponseCurve::power($p['a'], $p['b'], $c['support_max'], $c['support_min']),
                'saturation' => ResponseCurve::saturation($p['a'], $p['b'], $c['support_max'], $c['support_min']),
                default => null,
            };
            if (!$ch['curve']) return $this->no('Unsupported archived continuous model.');
            $channels[] = $ch;
        }
        $comparison = (new EntityScenarioComparison)->build($channels, array_replace($run->inputs, ['custom_allocation'=>$allocation]), $run->result);
        return ['status'=>'evaluated', 'type'=>'FROZEN_CUSTOM_SCENARIO', 'run_id'=>$run->id, 'snapshot_sha256'=>$run->snapshot_sha256,
            'objective'=>'MAXIMIZE_LEADS', 'response_metric'=>'typed_leads', 'currency'=>$run->result['currency'], 'window'=>$run->result['window'],
            'custom'=>$comparison['custom'], 'remaining_budget'=>$run->inputs['budget']-$comparison['custom']['total_spend'],
            'modeled_difference_vs_optimized'=>$comparison['custom']['modeled_response']-$run->result['scenario_response'],
            'note'=>'Same archived fitted curves, source population and constraints. No refit, current metric query, new optimization run or campaign change. Counts are declared leads, not verified quality, unique people or causal lift. Unequal spend is not pure reallocation.'];
    }
    private function no(string $reason): array { return ['status'=>'not_computable','reason'=>$reason]; }
}
