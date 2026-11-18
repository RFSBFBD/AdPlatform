<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\ConnectedAdAccount;
use Illuminate\Console\Command;

class SyncAllAccountsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'adplatform:sync-all {--type=incremental : Sync type (incremental, initial, historical)}';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch synchronization jobs for all active connected advertising accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $syncType = $this->option('type') ?? 'incremental';
        $accounts = ConnectedAdAccount::whereNotIn('status', ['revoked', 'expired'])->get();

        $this->info("Found {$accounts->count()} active connected accounts for synchronization [{$syncType}].");

        foreach ($accounts as $account) {
            SyncAccountJob::dispatch($account, $syncType);
            $this->line("Dispatched sync job for Account #{$account->id} ({$account->provider} - {$account->account_name})");
        }

        $this->info("All synchronization jobs dispatched successfully.");
        return Command::SUCCESS;
    }
}
