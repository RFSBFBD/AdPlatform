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

        // Resolved connection config (DB_URL is already parsed here). Config::get() would return the raw
        // host/port defaults and ignore DB_URL, which is how the Vercel + Neon setup is configured.
        $conn = DB::connection()->getConfig();
        $dbHost = $conn['host'] ?? 'postgres';
        $dbPort = $conn['port'] ?? '5432';
        $dbUser = $conn['username'] ?? 'ad_user';
        $dbPassword = $conn['password'] ?? 'ad_password_secret_local';
        $dbName = $conn['database'] ?? 'advertising_platform';
        $dbSslMode = $conn['sslmode'] ?? 'prefer';

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

            // Row count parity for EVERY public table (a fixed list of "key" tables missed audit_logs and
            // reported a passing backup that had silently lost a row).
            $primaryCounts = [];
            foreach ($primaryTables as $table) {
                $primaryCounts[$table] = (int) DB::table($table)->count();
            }

            $this->info("   Baseline captured: " . count($primaryTables) . " tables, " .
                count($primaryIndexes) . " indexes, " . count($primaryFks) . " foreign keys, " .
                count($primarySequences) . " sequences.");

            // STEP 2: Execute actual pg_dump
            $this->line("2. Executing actual pg_dump of database '{$dbName}'...");

            // No shell pipeline: a pipeline reports only the last command, so a failed pg_dump followed by a
            // successful gzip produced a valid empty archive. Each stage is run and checked on its own.
            $pgEnv = ['PGPASSWORD' => (string) $dbPassword, 'PGSSLMODE' => (string) $dbSslMode];

            $dumpProcess = new Process([
                'pg_dump', '-h', (string) $dbHost, '-p', (string) $dbPort, '-U', (string) $dbUser,
                '-d', (string) $dbName, '--no-owner', '--clean', '--if-exists', '--file', $backupFile,
            ], null, $pgEnv);
            $dumpProcess->setTimeout(120);
            $dumpProcess->run();

            if (!$dumpProcess->isSuccessful()) {
                throw new \RuntimeException("pg_dump failed (exit {$dumpProcess->getExitCode()}): " . $dumpProcess->getErrorOutput());
            }
            if (!File::exists($backupFile) || filesize($backupFile) === 0) {
                throw new \RuntimeException('pg_dump produced no output file.');
            }
            // A complete plain-format dump ends with this marker. A truncated or empty dump never has it.
            $tail = (string) file_get_contents($backupFile, false, null, max(0, filesize($backupFile) - 4096));
            if (!str_contains($tail, 'PostgreSQL database dump complete')) {
                throw new \RuntimeException('pg_dump output is incomplete (completion marker missing).');
            }

            // Compress in PHP as a byte-for-byte copy. The dump is never edited: filtering lines can drop real
            // data rows. Use pg_dump/psql clients whose major version matches the server (see the guide).
            $in = fopen($backupFile, 'rb');
            $gz = gzopen($compressedFile, 'wb9');
            if ($in === false || $gz === false) {
                throw new \RuntimeException('Could not open files to compress the dump.');
            }
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || ($chunk !== '' && gzwrite($gz, $chunk) === false)) {
                    throw new \RuntimeException('Compressing the dump failed.');
                }
            }
            fclose($in);
            gzclose($gz);

            if (!File::exists($compressedFile) || filesize($compressedFile) === 0) {
                throw new \RuntimeException('Compressed backup is missing or empty.');
            }

            $fileSize = filesize($compressedFile);
            $sha256 = hash_file('sha256', $compressedFile);
            File::put($checksumFile, "{$sha256}  " . basename($compressedFile) . "\n");

            $this->info("   pg_dump completed: {$fileSize} bytes. SHA-256: {$sha256}");

            // STEP 3: Verify archive integrity
            $this->line("3. Verifying archive integrity...");
            $gzipTest = new Process(['gzip', '-t', $compressedFile]);
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

            // Decompress to a plain file, then run psql on it directly (no shell pipeline, exit code checked).
            $restoreSql = "{$backupFile}.restore.sql";
            $gzIn = gzopen($compressedFile, 'rb');
            $out = fopen($restoreSql, 'wb');
            if ($gzIn === false || $out === false) {
                throw new \RuntimeException('Could not open the archive to restore it.');
            }
            while (!gzeof($gzIn)) {
                $chunk = gzread($gzIn, 1048576);
                if ($chunk === false) {
                    throw new \RuntimeException('Reading the archive failed.');
                }
                fwrite($out, $chunk);
            }
            gzclose($gzIn);
            fclose($out);

            $restoreProcess = new Process([
                'psql', '-h', (string) $dbHost, '-p', (string) $dbPort, '-U', (string) $dbUser,
                '-d', $tempDbName, '-v', 'ON_ERROR_STOP=1', '-f', $restoreSql,
            ], null, $pgEnv);
            $restoreProcess->setTimeout(180);
            $restoreProcess->run();

            if (!$restoreProcess->isSuccessful()) {
                throw new \RuntimeException("Restore into temporary database failed (exit {$restoreProcess->getExitCode()}): " . $restoreProcess->getErrorOutput());
            }
            $this->info("   Backup restored into '{$tempDbName}' successfully.");

            // STEP 6: Connect to isolated database and verify parity
            $this->line("6. Verifying schema, tables, indexes, constraints, and representative row counts...");

            $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$tempDbName};sslmode={$dbSslMode}";
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
                count($tempSequences) . " sequences, and row counts identical across " . count($primaryCounts) . " tables (all public tables).");

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
            if (File::exists("{$backupFile}.restore.sql")) File::delete("{$backupFile}.restore.sql");
            $this->info("   Temporary backup archives purged.");
        }
    }
}
