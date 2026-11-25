<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owner-scoped immutable-by-interface evidence, with accidental-change checksum, not tamper-proof storage. */
final class DecisionEvidenceArchive
{
    public function save(int $userId, array $result): int
    {
        $this->verify($result);

        return (int) DB::table('decision_evidence_runs')->insertGetId([
            'user_id' => $userId, 'pipeline_version' => $result['pipeline_version'], 'snapshot_id' => $result['snapshot_id'],
            'problem_id' => $result['problem_id'], 'audit_hash' => $result['audit_hash'],
            'evidence_json' => RecordedLedger::canonical($result), 'created_at' => now(),
        ]);
    }

    public function load(int $userId, int $id): ?array
    {
        $row = DB::table('decision_evidence_runs')->where('id', $id)->where('user_id', $userId)->first();
        if ($row === null) {
            return null;
        }
        $result = json_decode($row->evidence_json, true, 128, JSON_THROW_ON_ERROR);
        $this->verify($result);
        if (! hash_equals($row->audit_hash, $result['audit_hash']) || $row->snapshot_id !== $result['snapshot_id'] || $row->problem_id !== $result['problem_id']) {
            throw new InvalidArgumentException('evidence_archive_identity_mismatch');
        }

        return $result;
    }

    private function verify(array $result): void
    {
        $digest = $result['audit_hash'] ?? null;
        unset($result['audit_hash']);
        if (! is_string($digest) || ! hash_equals($digest, hash('sha256', RecordedLedger::canonical($result)))) {
            throw new InvalidArgumentException('evidence_archive_checksum_mismatch');
        }
    }
}
