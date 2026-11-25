<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Models\ConnectedAdAccount;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Original reporting bytes, append-only through this interface. Never source admission. */
final class RecordedReportingCapture
{
    public function persist(ConnectedAdAccount $account, SyncRun $run, array $pages): void
    {
        if ($run->connected_ad_account_id !== $account->id || count($pages) > 100) {
            throw new InvalidArgumentException('reporting_capture_scope_or_resource_limit');
        }
        $rows = [];
        $totalBytes = 0;
        foreach ($pages as $index => $page) {
            $totalBytes += is_string($page['body'] ?? null) ? strlen($page['body']) : 0;
            if ($totalBytes > 16 * 1024 * 1024) {
                throw new InvalidArgumentException('reporting_capture_total_byte_limit');
            }
            if (! is_int($index) || ! is_array($page) || ! is_string($page['body'] ?? null) || strlen($page['body']) > 8 * 1024 * 1024
                || ! is_string($page['sha256'] ?? null) || ! hash_equals($page['sha256'], hash('sha256', $page['body']))
                || ($page['account_external_id'] ?? null) !== $account->external_account_id
                || ! is_array($page['request'] ?? null) || ! is_string($page['request']['api_version'] ?? null)) {
                throw new InvalidArgumentException('reporting_capture_integrity_or_account_mismatch');
            }
            $provenance = $page;
            unset($provenance['body'],$provenance['sha256']);
            $native = null;
            if ($account->provider === 'google') {
                try {
                    $native = (new GoogleNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            if ($account->provider === 'meta') {
                try {
                    $native = (new MetaNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            if ($account->provider === 'reddit') {
                try {
                    $native = (new RedditNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            if ($account->provider === 'spotify') {
                try {
                    $native = (new SpotifyNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            if ($account->provider === 'apple') {
                try {
                    $native = (new AppleNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            if ($account->provider === 'pinterest') {
                try {
                    $native = (new PinterestNativeRecordedMetrics)->extract($page['body'], $provenance);
                } catch (InvalidArgumentException|\JsonException $e) {
                    $native = ['status' => 'native_extraction_rejected', 'reason' => $e->getMessage(), 'rows' => []];
                }
            }
            $rows[] = ['connected_ad_account_id' => $account->id, 'sync_run_id' => $run->id, 'provider' => $account->provider,
                'page_index' => $index, 'body' => $page['body'], 'body_sha256' => $page['sha256'],
                'native_metrics_json' => $native === null ? null : json_encode($native, JSON_THROW_ON_ERROR),
                'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR), 'admission_state' => 'unqualified_original_reporting_bytes', 'created_at' => now()];
        }
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                DB::table('recorded_reporting_responses')->insert($row);
            }
        });
    }
}
