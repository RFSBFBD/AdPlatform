<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Models\ConnectedAdAccount;
use App\Models\SyncRun;
use App\Services\Advertising\AccountMetadataEvidence;
use App\Services\Decision\Accounting\RecordedLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Account-owned frozen exact delivery operands. No write back or operational approval. */
final class RecordedDeliveryRepository
{
    public function append(ConnectedAdAccount $account, SyncRun $run, array $roster): array
    {
        if ($run->connected_ad_account_id !== $account->id || $account->provider !== 'google') {
            throw new InvalidArgumentException('recorded_delivery_repository_scope');
        }
        $captured = DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->where('connected_ad_account_id', $account->id)->orderBy('page_index')->get();
        $pages = [];
        foreach ($captured as $index => $p) {
            if ($p->page_index !== $index || $p->provider !== 'google') {
                throw new InvalidArgumentException('recorded_delivery_repository_page_order');
            }
            $pages[] = ['body' => $p->body, 'sha256' => $p->body_sha256, 'provenance' => json_decode($p->provenance, true, 512, JSON_THROW_ON_ERROR)];
        }
        $first = $pages[0]['provenance'] ?? [];
        try {
            $ledger = (new GoogleRecordedSnapshotBuilder)->build($pages, [
                'provider' => 'google', 'account_id' => (string) $account->id, 'account_external_id' => $account->external_account_id,
                'currency' => $account->currency, 'timezone' => $account->timezone,
                'start_date' => $first['start_date'] ?? null, 'end_date' => $first['end_date'] ?? null,
                'metadata_marker' => AccountMetadataEvidence::marker($account), 'roster_status' => $roster['fetch_status'] ?? null,
                'campaign_ids' => array_column($roster['campaigns'] ?? [], 'external_campaign_id')]);
        } catch (InvalidArgumentException $e) {
            return ['status' => 'not_available', 'reason' => $e->getMessage()];
        }
        $data = $ledger->jsonSerialize();
        $body = RecordedLedger::canonical($data);
        DB::table('recorded_delivery_snapshots')->insert(['connected_ad_account_id' => $account->id, 'sync_run_id' => $run->id,
            'snapshot_id' => $data['snapshot_id'], 'body' => $body, 'body_sha256' => hash('sha256', $body), 'created_at' => now()]);

        return ['status' => 'recorded_returned_row_delivery_only', 'snapshot_id' => $data['snapshot_id'],
            'source_truth' => 'provider_reconciliation_not_performed', 'finality' => 'retrieval_time_report_not_final_bill', 'optimization_admission' => false];
    }

    /** Distinguishes never-captured legacy history from a failed/stale/missing current original source. */
    public function selectForAccount(ConnectedAdAccount $account, int $ownerId, string $start, string $end): array
    {
        if ($account->user_id !== $ownerId || $account->provider !== 'google') {
            throw new InvalidArgumentException('recorded_delivery_owner_mismatch');
        }
        try {
            $snapshot = $this->latestForAccount($account, $ownerId, $start, $end);
        } catch (InvalidArgumentException|\JsonException) {
            return ['status' => 'unavailable_original', 'reason' => 'original_source_integrity_or_scope_revalidation_failed'];
        }
        if ($snapshot !== null) {
            return ['status' => 'available_original', 'snapshot' => $snapshot];
        }
        $captured = DB::table('recorded_reporting_responses')->where('connected_ad_account_id', $account->id)->exists()
            || DB::table('recorded_delivery_snapshots')->where('connected_ad_account_id', $account->id)->exists();

        return $captured ? ['status' => 'unavailable_original', 'reason' => 'current_original_window_missing_failed_or_superseded_no_legacy_substitution']
            : ['status' => 'legacy_only_no_original_capture'];
    }

    public function latestForAccount(ConnectedAdAccount $account, int $ownerId, string $start, string $end): ?array
    {
        if ($account->user_id !== $ownerId || $account->provider !== 'google') {
            throw new InvalidArgumentException('recorded_delivery_owner_mismatch');
        }
        // Only the latest run is eligible. Never resurrect an older snapshot after a newer failed or unsupported run.
        $run = SyncRun::where('connected_ad_account_id', $account->id)->orderByDesc('id')->first();
        if (! $run || $run->status !== 'completed' || ($run->metadata['start_date'] ?? null) !== $start || ($run->metadata['end_date'] ?? null) !== $end) {
            return null;
        }
        $record = DB::table('recorded_delivery_snapshots')->where('sync_run_id', $run->id)->where('connected_ad_account_id', $account->id)->first();
        if (! $record) {
            return null;
        }
        if (! hash_equals($record->body_sha256, hash('sha256', $record->body))) {
            throw new InvalidArgumentException('recorded_delivery_integrity_mismatch');
        }
        $data = json_decode($record->body, true, 512, JSON_THROW_ON_ERROR);
        $hashBody = $data;
        unset($hashBody['snapshot_id']);
        if (! hash_equals($record->snapshot_id, hash('sha256', RecordedLedger::canonical($hashBody)))
            || ($data['scope']['account_id'] ?? null) !== (string) $account->id || ($data['scope']['currency'] ?? null) !== $account->currency
            || ($data['scope']['timezone'] ?? null) !== $account->timezone || ($data['scope']['reporting_window'] ?? null) !== $start.'/'.$end
            || AccountMetadataEvidence::marker($account) === 'unverified_legacy_or_missing') {
            throw new InvalidArgumentException('recorded_delivery_snapshot_scope_or_hash_mismatch');
        }

        $lineage = $data['scope']['source_lineage'] ?? null;
        if (! is_array($lineage) || ($lineage['version'] ?? null) !== 'google-returned-row-lineage-1' || ! is_array($lineage['pages'] ?? null)) {
            throw new InvalidArgumentException('recorded_delivery_original_lineage_required');
        }
        $captured = DB::table('recorded_reporting_responses')->where('sync_run_id', $run->id)->where('connected_ad_account_id', $account->id)->orderBy('page_index')->get();
        if ($captured->count() !== count($lineage['pages'])) {
            throw new InvalidArgumentException('recorded_delivery_original_page_population_mismatch');
        }
        $pages = [];
        foreach ($captured as $index => $capture) {
            if ($capture->page_index !== $index || $capture->provider !== 'google' || ! hash_equals($capture->body_sha256, hash('sha256', $capture->body))) {
                throw new InvalidArgumentException('recorded_delivery_original_page_integrity_mismatch');
            }
            $pages[] = ['body' => $capture->body, 'sha256' => $capture->body_sha256, 'provenance' => json_decode($capture->provenance, true, 512, JSON_THROW_ON_ERROR)];
        }
        $reconstructed = (new GoogleRecordedSnapshotBuilder)->build($pages, [
            'provider' => 'google', 'account_id' => (string) $account->id, 'account_external_id' => $account->external_account_id,
            'currency' => $account->currency, 'timezone' => $account->timezone, 'start_date' => $start, 'end_date' => $end,
            'metadata_marker' => AccountMetadataEvidence::marker($account), 'roster_status' => $lineage['roster_status'] ?? null, 'campaign_ids' => $lineage['roster_campaign_ids'] ?? null,
        ])->jsonSerialize();
        if (! hash_equals($data['snapshot_id'], $reconstructed['snapshot_id'])) {
            throw new InvalidArgumentException('recorded_delivery_original_source_reconstruction_mismatch');
        }

        return $data;
    }
}
