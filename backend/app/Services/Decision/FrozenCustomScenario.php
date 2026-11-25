<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;

/** Read-only evaluation of a custom allocation against archived models, never a new optimization or campaign write. */
final class FrozenCustomScenario
{
    public function evaluate(array $archive, array $allocation): array
    {
        $digest = $archive['audit_hash'] ?? null;
        $body = $archive;
        unset($body['audit_hash']);
        if (! is_string($digest) || ! hash_equals($digest, hash('sha256', RecordedLedger::canonical($body)))) {
            throw new \InvalidArgumentException('custom_scenario_archive_checksum_mismatch');
        }
        $base = ['snapshot_id' => $archive['snapshot_id'], 'problem_id' => $archive['problem_id'], 'parent_audit_hash' => $digest, 'type' => 'SCENARIO', 'operational_approval' => false, 'stored_archive_changed' => false,
            'limits' => 'Read-only custom budget evaluation using frozen models and original constraints. Not a new optimization, prediction, causal lift or campaign instruction. Original archive remains unchanged.'];
        if (($archive['status'] ?? null) !== 'checked_reference_scenario' || ($archive['scenarios']['optimized']['check']['status'] ?? null) !== 'checked') {
            return $base + ['status' => 'unavailable', 'reason' => 'checked_archived_optimization_required'];
        }
        if (RecordedLedger::canonical($archive['execution_manifest'] ?? null) !== RecordedLedger::canonical((new DecisionExecutionManifest)->capture())) {
            return $base + ['status' => 'unavailable', 'reason' => 'frozen_execution_manifest_mismatch'];
        }
        $spec = $archive['problem'];
        unset($spec['problem_version']);
        $problem = new DecisionProblem($spec);
        if ($problem->id() !== $archive['problem_id']) {
            throw new \InvalidArgumentException('custom_scenario_problem_identity_mismatch');
        }
        $models = [];
        foreach ($archive['model_snapshot'] as $id => $m) {
            if (! is_array($m['curve'] ?? null)) {
                return $base + ['status' => 'unavailable', 'reason' => 'frozen_curve_missing'];
            }$m['curve'] = (new FrozenModelPlotEvidence)->restore($m['curve']);
            $models[$id] = $m;
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation);
        $custom = ['allocation' => $allocation, 'check' => $check];
        $comparison = $archive;
        $comparison['scenarios']['custom'] = $custom;

        return $base + ['status' => $check['status'] === 'checked' ? 'checked_custom_scenario' : 'rejected_custom_scenario', 'custom' => $custom, 'comparison' => (new FrozenScenarioComparison)->build($comparison), 'optimized_reference' => $archive['scenarios']['optimized'], 'currency' => $spec['currency'], 'response_unit' => $spec['response_unit']];
    }
}
