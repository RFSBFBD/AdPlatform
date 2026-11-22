<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAccountJob;
use App\Models\ConnectedAdAccount;
use App\Models\SyncRun;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected AdDataSyncEngine $syncEngine
    ) {}

    /**
     * Trigger a synchronization for a specific connected ad account.
     */
    public function triggerSync(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAdAccount::findOrFail($id);

        if ($account->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to sync this ad account.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'sync_type' => ['nullable', 'string', 'in:initial,incremental,historical,manual'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'sync_now' => ['nullable', 'boolean'],
        ]);

        $syncType = $validated['sync_type'] ?? 'manual';
        $syncNow = $validated['sync_now'] ?? false;

        if ($syncNow) {
            // Run immediately in this process
            $syncRun = $this->syncEngine->syncAccount(
                $account,
                $syncType,
                $validated['start_date'] ?? null,
                $validated['end_date'] ?? null
            );

            $status = (string) ($syncRun->status ?? 'unknown');
            return response()->json([
                'message' => $status === 'completed' ? 'Synchronization completed.' : "Synchronization finished with status: {$status}.",
                'status' => $status,
                'sync_run' => $syncRun,
            ]);
        }

        // Dispatch to background queue worker
        SyncAccountJob::dispatch(
            $account,
            $syncType,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null
        );

        return response()->json([
            'message' => "Synchronization queued for {$account->account_name} ({$account->provider}).",
            'account_id' => $account->id,
            'status' => 'queued',
        ], 202);
    }

    /**
     * Get the latest sync status for an ad account.
     */
    public function getStatus(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAdAccount::findOrFail($id);

        if ($account->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have access to this ad account.',
                ],
            ], 403);
        }

        $latestSync = SyncRun::where('connected_ad_account_id', $account->id)
            ->latest('id')
            ->first();

        return response()->json([
            'account_id' => $account->id,
            'account_name' => $account->account_name,
            'provider' => $account->provider,
            'status' => $account->status,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'latest_sync' => $latestSync ? [
                'id' => $latestSync->id,
                'sync_type' => $latestSync->sync_type,
                'status' => $latestSync->status,
                'started_at' => $latestSync->started_at?->toIso8601String(),
                'completed_at' => $latestSync->completed_at?->toIso8601String(),
                'records_processed' => $latestSync->records_processed,
                'error_message' => $latestSync->error_message,
            ] : null,
        ]);
    }

    /**
     * Get sync history runs for an ad account.
     */
    public function getHistory(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAdAccount::findOrFail($id);

        if ($account->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have access to this ad account.',
                ],
            ], 403);
        }

        $runs = SyncRun::where('connected_ad_account_id', $account->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'account_id' => $account->id,
            'runs' => $runs,
        ]);
    }
}
