<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

class VerifyDatabaseBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:verify-backup 
                            {--output-dir=storage/app/backups : Directory to write test backup}
                            {--keep-temp : Retain temporary database for manual inspection on failure}';

    /**
     * The console command description.
     */
    protected $description = 'Execute real pg_dump, test archive integrity, restore into an isolated temporary database, and verify schemas, tables, indexes, constraints, and row counts';

    public function handle(): int
    {
        $this->info("=== Starting Genuine PostgreSQL Backup & Restore Verification ===");

        $outputDir = base_path($this->option('output-dir'));
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $timestamp = date('Ymd_His') . '_' . mt_rand(100, 999);
        $tempDbName = "backup_verify_test_{$timestamp}";
        $backupFile = "{$outputDir}/verify_{$timestamp}.sql";
        $compressedFile = "{$backupFile}.gz";
        $checksumFile = "{$backupFile}.sha256";

        $dbHost = Config::get('database.connections.pgsql.host', 'postgres');
        $dbPort = Config::get('database.connections.pgsql.port', '5432');
        $dbUser = Config::get('database.connections.pgsql.username', 'ad_user');
        $dbPassword = Config::get('database.connections.pgsql.password', 'ad_password_secret_local');
        $dbName = Config::get('database.connections.pgsql.database', 'advertising_platform');

        $tempDbCreated = false;

        try {
            // STEP 1: Query Primary DB Baseline (Read-Only, 0 Writes)
            $this->line("1. Capturing primary database baseline metrics (read-only)...");

            $primaryTables = collect(DB::select(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
            ))->pluck('table_name')->toArray();

            $primaryIndexes = collect(DB::select(
                "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' ORDER BY indexname"
            ))->pluck('indexname')->toArray();

            $primaryFks = collect(DB::select(
                "SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_type = 'FOREIGN KEY' AND table_schema = 'public' ORDER BY constraint_name"
            ))->pluck('constraint_name')->toArray();

            $primarySequences = collect(DB::select(
                "SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public' ORDER BY sequence_name"
            ))->pluck('sequence_name')->toArray();

            // Representative key tables to verify row count parity
            $checkTables = [
                'users', 'connected_ad_accounts', 'campaigns', 'ad_sets', 'ads',
                'performance_metrics', 'exchange_rates', 'provider_developer_configs',
                'ai_configs', 'billing_invoices'
            ];

            $primaryCounts = [];
            foreach ($checkTables as $table) {
                if (in_array($table, $primaryTables, true)) {
                    $primaryCounts[$table] = (int) DB::table($table)->count();
                }
            }

            $this->info("   Baseline captured: " . count($primaryTables) . " tables, " .
                count($primaryIndexes) . " indexes, " . count($primaryFks) . " foreign keys, " .
                count($primarySequences) . " sequences.");

            // STEP 2: Execute actual pg_dump
            $this->line("2. Executing actual pg_dump of database '{$dbName}'...");

            $pgDumpCmd = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s --no-owner --clean --if-exists | sed \'/transaction_timeout/d\' | gzip -9 > %s',
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg((string) $dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($compressedFile)
            );

            $dumpProcess = Process::fromShellCommandline($pgDumpCmd);
            $dumpProcess->setTimeout(120);
            $dumpProcess->run();

            if (!$dumpProcess->isSuccessful() || !File::exists($compressedFile) || filesize($compressedFile) === 0) {
                throw new \RuntimeException("pg_dump failed: " . $dumpProcess->getErrorOutput());
            }

            $fileSize = filesize($compressedFile);
            $sha256 = hash_file('sha256', $compressedFile);
            File::put($checksumFile, "{$sha256}  " . basename($compressedFile) . "\n");

            $this->info("   pg_dump completed: {$fileSize} bytes. SHA-256: {$sha256}");

            // STEP 3: Verify archive integrity
            $this->line("3. Verifying archive integrity...");
            $gzipTest = Process::fromShellCommandline(sprintf('gzip -t %s', escapeshellarg($compressedFile)));
            $gzipTest->run();

            if (!$gzipTest->isSuccessful()) {
                throw new \RuntimeException("Backup archive is corrupt: " . $gzipTest->getErrorOutput());
            }
            $this->info("   Archive integrity verified cleanly via gzip -t.");

            // STEP 4: Create isolated temporary database
            $this->line("4. Creating isolated temporary database [{$tempDbName}]...");
            DB::statement("CREATE DATABASE \"{$tempDbName}\";");
            $tempDbCreated = true;
            $this->info("   Temporary isolated database '{$tempDbName}' created.");

            // STEP 5: Restore backup into isolated temporary database
            $this->line("5. Restoring actual backup into isolated temporary database...");

            $restoreCmd = sprintf(
                'gunzip -c %s | PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1',
                escapeshellarg($compressedFile),
                escapeshellarg($dbPassword),
                escapeshellarg($dbHost),
                escapeshellarg((string) $dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($tempDbName)
            );

            $restoreProcess = Process::fromShellCommandline($restoreCmd);
            $restoreProcess->setTimeout(180);
            $restoreProcess->run();

            if (!$restoreProcess->isSuccessful()) {
                throw new \RuntimeException("Restore into temporary database failed: " . $restoreProcess->getErrorOutput());
            }
            $this->info("   Backup restored into '{$tempDbName}' successfully.");

            // STEP 6: Connect to isolated database and verify parity
            $this->line("6. Verifying schema, tables, indexes, constraints, and representative row counts...");

            $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$tempDbName}";
            $tempPdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // 6a. Verify Tables
            $tempTablesStmt = $tempPdo->query(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
            );
            $tempTables = $tempTablesStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tempTables !== $primaryTables) {
                $missing = array_diff($primaryTables, $tempTables);
                throw new \RuntimeException("Table mismatch in restored database! Missing: " . implode(', ', $missing));
            }

            // 6b. Verify Indexes
            $tempIndexesStmt = $tempPdo->query("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' ORDER BY indexname");
            $tempIndexes = $tempIndexesStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tempIndexes !== $primaryIndexes) {
                $missing = array_diff($primaryIndexes, $tempIndexes);
                throw new \RuntimeException("Index mismatch in restored database! Missing: " . implode(', ', $missing));
            }

            // 6c. Verify Foreign Keys
            $tempFkStmt = $tempPdo->query(
                "SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_type = 'FOREIGN KEY' AND table_schema = 'public' ORDER BY constraint_name"
            );
            $tempFks = $tempFkStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tempFks !== $primaryFks) {
                $missing = array_diff($primaryFks, $tempFks);
                throw new \RuntimeException("Foreign key constraint mismatch! Missing: " . implode(', ', $missing));
            }

            // 6d. Verify Sequences
            $tempSeqStmt = $tempPdo->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public' ORDER BY sequence_name");
            $tempSequences = $tempSeqStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($tempSequences !== $primarySequences) {
                $missing = array_diff($primarySequences, $tempSequences);
                throw new \RuntimeException("Sequence mismatch! Missing: " . implode(', ', $missing));
            }

            // 6e. Verify Representative Row Counts
            foreach ($primaryCounts as $table => $expectedCount) {
                $countStmt = $tempPdo->query("SELECT COUNT(*) FROM \"{$table}\"");
                $actualCount = (int) $countStmt->fetchColumn();
                if ($actualCount !== $expectedCount) {
                    throw new \RuntimeException("Row count parity check failed on '{$table}': expected {$expectedCount}, restored {$actualCount}");
                }
            }

            $this->info("   All parity checks passed: " . count($tempTables) . " tables, " .
                count($tempIndexes) . " indexes, " . count($tempFks) . " foreign keys, " .
                count($tempSequences) . " sequences, and row counts identical across " . count($primaryCounts) . " representative tables.");

            unset($tempPdo); // close connection

            $this->info("=== Genuine PostgreSQL Backup & Restore Verification: PASSED ===");
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Backup verification failed: " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            // STEP 7: Cleanup Temporary Resources Safely
            $this->line("7. Cleaning up temporary verification resources safely...");

            if ($tempDbCreated && !$this->option('keep-temp')) {
                try {
                    // Terminate open connections to temp database
                    DB::statement(
                        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$tempDbName}' AND pid <> pg_backend_pid();"
                    );
                    DB::statement("DROP DATABASE IF EXISTS \"{$tempDbName}\";");
                    $this->info("   Temporary database '{$tempDbName}' safely dropped.");
                } catch (Throwable $dropEx) {
                    $this->warn("   Failed to drop temporary database '{$tempDbName}': " . $dropEx->getMessage());
                }
            }

            // Clean up files
            if (File::exists($backupFile)) File::delete($backupFile);
            if (File::exists($compressedFile)) File::delete($compressedFile);
            if (File::exists($checksumFile)) File::delete($checksumFile);
            $this->info("   Temporary backup archives purged.");
        }
    }
}
