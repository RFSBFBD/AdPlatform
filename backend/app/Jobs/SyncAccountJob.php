<?php

namespace App\Jobs;

use App\Models\ConnectedAdAccount;
use App\Services\Advertising\Sync\AdDataSyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job (exponential backoff: 1m, 5m, 15m).
     */
    public array $backoff = [60, 300, 900];

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public ConnectedAdAccount $account,
        public string $syncType = 'incremental',
        public ?string $startDate = null,
        public ?string $endDate = null
    ) {}

    public function handle(AdDataSyncEngine $syncEngine): void
    {
        Log::info("Starting SyncAccountJob for account #{$this->account->id} ({$this->account->provider}) [Type: {$this->syncType}]");

        $syncRun = $syncEngine->syncAccount(
            $this->account,
            $this->syncType,
            $this->startDate,
            $this->endDate
        );

        if ($syncRun->status === 'failed') {
            Log::warning("SyncAccountJob failed for account #{$this->account->id}: {$syncRun->error_message}");
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        } else {
            Log::info("SyncAccountJob completed successfully for account #{$this->account->id}. Processed: {$syncRun->records_processed} records.");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("SyncAccountJob permanently failed for account #{$this->account->id}: " . ($exception?->getMessage() ?? 'Unknown error'));
        $this->account->update(['status' => 'error']);
    }
}
