<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;
use InvalidArgumentException;

/** Frozen-input recomputation using current executable code/config, gated by a local implementation fingerprint. */
final class DecisionEvidenceReplay
{
    public function replay(array $archive): array
    {
        $digest = $archive['audit_hash'] ?? null;
        $body = $archive;
        unset($body['audit_hash']);
        if (! is_string($digest) || ! hash_equals($digest, hash('sha256', RecordedLedger::canonical($body)))) {
            throw new InvalidArgumentException('replay_archive_checksum_mismatch');
        }
        if (($archive['pipeline_version'] ?? '') !== 'integrated-reference-v1') {
            throw new InvalidArgumentException('replay_version_mismatch');
        }
        $currentManifest = (new DecisionExecutionManifest)->capture();
        if (! isset($archive['execution_manifest']) || RecordedLedger::canonical($archive['execution_manifest']) !== RecordedLedger::canonical($currentManifest)) {
            return ['status' => 'replay_unavailable_execution_manifest_mismatch', 'matches_scenarios' => null, 'matches_consumers' => null,
                'snapshot_id' => $archive['snapshot_id'], 'problem_id' => $archive['problem_id'], 'recomputed' => false,
                'limits' => 'Stored evidence is unchanged. This installation does not match the recorded local code/dependency fingerprint; frozen-input recomputation was not run. A missing manifest is not silently upgraded.'];
        }
        $rows = [];
        foreach ($archive['actual']['rows'] as $key => $row) {
            $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'],
                'reporting_date' => $row['reporting_date'], 'scope' => $archive['actual']['scope']];
            foreach ($row['metrics'] as $metric => $value) {
                $r[$metric] = $value['lexeme'];
            }
            $rows[] = $r;
        }
        $ledger = isset($archive['actual']['source_envelope_json'])
            ? RecordedLedger::fromRecordedJson($archive['actual']['scope'], $archive['actual']['source_envelope_json'], $archive['actual']['linked_funnel'] ?? null)
            : new RecordedLedger($archive['actual']['scope'], $rows, null, $archive['actual']['linked_funnel'] ?? null);
        if ($ledger->jsonSerialize()['snapshot_id'] !== $archive['snapshot_id']) {
            throw new InvalidArgumentException('replay_source_snapshot_mismatch');
        }
        $spec = $archive['problem'];
        unset($spec['problem_version']);
        $problem = new DecisionProblem($spec);
        if ($problem->id() !== $archive['problem_id']) {
            throw new InvalidArgumentException('replay_problem_mismatch');
        }
        $models = [];
        foreach ($archive['model_snapshot'] as $id => $m) {
            $c = $m['curve'] ?? null;
            if ($c === null) {
                $models[$id] = $m;

                continue;
            }
            $m['curve'] = (new FrozenModelPlotEvidence)->restore($c);
            $models[$id] = $m;
        }
        $custom = $archive['scenarios']['custom']['allocation'] ?? null;
        $replayed = (new IntegratedDecisionPipeline)->run($ledger, $problem, $models, $custom);

        $matchesScenarios = RecordedLedger::canonical($replayed['scenarios']) === RecordedLedger::canonical($archive['scenarios']);
        $matchesConsumers = RecordedLedger::canonical($replayed['consumers']) === RecordedLedger::canonical($archive['consumers']);
        $matchesStatus = $replayed['status'] === $archive['status'];
        $matchesCertificate = RecordedLedger::canonical($replayed['certificate'] ?? null) === RecordedLedger::canonical($archive['certificate'] ?? null);

        return ['status' => $matchesScenarios && $matchesConsumers && $matchesStatus && $matchesCertificate ? 'replayed' : 'recomputed_mismatch',
            'recomputed' => true, 'matches_scenarios' => $matchesScenarios, 'matches_consumers' => $matchesConsumers,
            'matches_status' => $matchesStatus, 'matches_certificate' => $matchesCertificate,
            'snapshot_id' => $archive['snapshot_id'], 'problem_id' => $problem->id(), 'result' => $replayed,
            'limits' => 'Frozen-input recomputation uses this installation and its current solver configuration, not a captured environment. Status, scenarios, consumers and certificate are compared exactly, no approximate tie equivalence is invented. Runtime/library/tie changes may cause mismatch. Stored evidence is unchanged. No new provider validation or empirical model calibration; checksum is not privileged tamper resistance.'];
    }
}
