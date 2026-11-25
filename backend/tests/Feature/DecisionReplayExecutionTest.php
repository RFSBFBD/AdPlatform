<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionExecutionManifest;
use Tests\TestCase;

final class DecisionReplayExecutionTest extends TestCase
{
    public function test_missing_or_different_execution_manifest_never_claims_replay_or_requires_live_source(): void
    {
        $body = ['pipeline_version' => 'integrated-reference-v1', 'snapshot_id' => 'synthetic', 'problem_id' => 'synthetic-problem'];
        foreach ([null, ['version' => 'other']] as $manifest) {
            $a = $body;
            if ($manifest !== null) {
                $a['execution_manifest'] = $manifest;
            }
            $a['audit_hash'] = hash('sha256', RecordedLedger::canonical($a));
            $replay = (new DecisionEvidenceReplay)->replay($a);
            self::assertSame('replay_unavailable_execution_manifest_mismatch', $replay['status']);
            self::assertFalse($replay['recomputed']);
            self::assertNull($replay['matches_scenarios']);
        }
        $manifest = (new DecisionExecutionManifest)->capture();
        self::assertArrayHasKey('composer.lock', $manifest['implementation_files_sha256']);
        self::assertArrayHasKey('solver/typed_concave_proposal.py', $manifest['implementation_files_sha256']);
        self::assertSame($manifest, (new DecisionExecutionManifest)->capture());
    }
}
