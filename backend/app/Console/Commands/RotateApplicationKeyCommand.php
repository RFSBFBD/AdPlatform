<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class RotateApplicationKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'app:rotate-key 
                            {--old-key= : The old base64 encoded APP_KEY used to decrypt existing records}
                            {--new-key= : The new base64 encoded APP_KEY to encrypt records with}
                            {--dry-run : Validate that all records can be decrypted and re-encrypted without writing changes}
                            {--manifest-path= : Optional custom path to write recovery manifest JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Safely re-encrypt all credentials and secrets when rotating the application key with dry-run and manifest support';

    public function handle(): int
    {
        $this->info("=== Starting Application Key Rotation Routine ===");

        // 1. Prevent concurrent rotation operations via atomic Redis cache lock (Phase 5)
        $lock = Cache::lock('key_rotation_in_progress', 600);
        if (!$lock->get()) {
            $this->error("CONCURRENCY ERROR: Another key rotation operation is currently in progress.");
            return Command::FAILURE;
        }

        $startTime = microtime(true);
        $isDryRun = (bool) $this->option('dry-run');
        if ($isDryRun) {
            $this->warn(">>> DRY-RUN MODE ACTIVE: No database changes will be committed. <<<");
        }

        $oldKeyRaw = $this->option('old-key') ?: Config::get('app.key');
        $newKeyRaw = $this->option('new-key');

        if (!$newKeyRaw) {
            $newKeyRaw = 'base64:' . base64_encode(Encrypter::generateKey(Config::get('app.cipher', 'AES-256-CBC')));
            $this->info("Generated new application encryption key: {$newKeyRaw}");
        }

        $cipher = Config::get('app.cipher', 'AES-256-CBC');
        $oldKey = $this->parseKey($oldKeyRaw);
        $newKey = $this->parseKey($newKeyRaw);

        $oldEncrypter = new Encrypter($oldKey, $cipher);
        $newEncrypter = new Encrypter($newKey, $cipher);

        // Build list of previous encrypters for fallback decryption
        $previousEncrypters = [];
        $previousKeys = Config::get('app.previous_keys', []);
        foreach ($previousKeys as $pKeyRaw) {
            if (!empty($pKeyRaw)) {
                $previousEncrypters[] = new Encrypter($this->parseKey($pKeyRaw), $cipher);
            }
        }

        $manifest = [
            'timestamp' => now()->toIso8601String(),
            'is_dry_run' => $isDryRun,
            'cipher' => $cipher,
            'old_key_sha256' => hash('sha256', $oldKeyRaw),
            'new_key_sha256' => hash('sha256', $newKeyRaw),
            'previous_keys_count' => count($previousEncrypters),
            'status' => 'pending',
            'tables' => [
                'ai_configs' => ['total' => 0, 're_encrypted' => 0, 'already_new_key' => 0],
                'provider_developer_configs' => ['total' => 0, 're_encrypted' => 0, 'already_new_key' => 0],
                'connected_ad_accounts' => ['total' => 0, 're_encrypted' => 0, 'already_new_key' => 0],
            ],
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            // STEP 1: Re-encrypt ai_configs
            $this->line("1. Scanning 'ai_configs' table...");
            $aiRows = DB::table('ai_configs')->whereNotNull('api_key')->get();
            $manifest['tables']['ai_configs']['total'] = $aiRows->count();

            foreach ($aiRows as $row) {
                $rawCipher = $row->api_key;
                
                // Resumable check: is it already encrypted with new key?
                if ($this->canDecrypt($rawCipher, $newEncrypter)) {
                    $manifest['tables']['ai_configs']['already_new_key']++;
                    continue;
                }

                $plaintext = $this->decryptWithFallback($rawCipher, $oldEncrypter, $previousEncrypters, 'ai_configs', $row->id, 'api_key');
                $newCipher = $newEncrypter->encryptString($plaintext);

                if (!$isDryRun) {
                    DB::table('ai_configs')->where('id', $row->id)->update(['api_key' => $newCipher]);
                }
                $manifest['tables']['ai_configs']['re_encrypted']++;
            }
            $this->info("   ai_configs: {$manifest['tables']['ai_configs']['re_encrypted']} re-encrypted, {$manifest['tables']['ai_configs']['already_new_key']} already on new key.");

            // STEP 2: Re-encrypt provider_developer_configs
            $this->line("2. Scanning 'provider_developer_configs' table...");
            $devRows = DB::table('provider_developer_configs')->get();
            $manifest['tables']['provider_developer_configs']['total'] = $devRows->count();

            foreach ($devRows as $row) {
                $updates = [];

                if (!empty($row->app_secret)) {
                    if (!$this->canDecrypt($row->app_secret, $newEncrypter)) {
                        $pt = $this->decryptWithFallback($row->app_secret, $oldEncrypter, $previousEncrypters, 'provider_developer_configs', $row->id, 'app_secret');
                        $updates['app_secret'] = $newEncrypter->encryptString($pt);
                    } else {
                        $manifest['tables']['provider_developer_configs']['already_new_key']++;
                    }
                }

                if (!empty($row->developer_token)) {
                    if (!$this->canDecrypt($row->developer_token, $newEncrypter)) {
                        $pt = $this->decryptWithFallback($row->developer_token, $oldEncrypter, $previousEncrypters, 'provider_developer_configs', $row->id, 'developer_token');
                        $updates['developer_token'] = $newEncrypter->encryptString($pt);
                    } else {
                        $manifest['tables']['provider_developer_configs']['already_new_key']++;
                    }
                }

                if (!empty($updates)) {
                    if (!$isDryRun) {
                        DB::table('provider_developer_configs')->where('id', $row->id)->update($updates);
                    }
                    $manifest['tables']['provider_developer_configs']['re_encrypted']++;
                }
            }
            $this->info("   provider_developer_configs: {$manifest['tables']['provider_developer_configs']['re_encrypted']} re-encrypted.");

            // STEP 3: Re-encrypt connected_ad_accounts
            $this->line("3. Scanning 'connected_ad_accounts' table...");
            $accRows = DB::table('connected_ad_accounts')->get();
            $manifest['tables']['connected_ad_accounts']['total'] = $accRows->count();

            foreach ($accRows as $row) {
                $updates = [];

                if (!empty($row->access_token)) {
                    if (!$this->canDecrypt($row->access_token, $newEncrypter)) {
                        $pt = $this->decryptWithFallback($row->access_token, $oldEncrypter, $previousEncrypters, 'connected_ad_accounts', $row->id, 'access_token');
                        $updates['access_token'] = $newEncrypter->encryptString($pt);
                    } else {
                        $manifest['tables']['connected_ad_accounts']['already_new_key']++;
                    }
                }

                if (!empty($row->refresh_token)) {
                    if (!$this->canDecrypt($row->refresh_token, $newEncrypter)) {
                        $pt = $this->decryptWithFallback($row->refresh_token, $oldEncrypter, $previousEncrypters, 'connected_ad_accounts', $row->id, 'refresh_token');
                        $updates['refresh_token'] = $newEncrypter->encryptString($pt);
                    } else {
                        $manifest['tables']['connected_ad_accounts']['already_new_key']++;
                    }
                }

                if (!empty($updates)) {
                    if (!$isDryRun) {
                        DB::table('connected_ad_accounts')->where('id', $row->id)->update($updates);
                    }
                    $manifest['tables']['connected_ad_accounts']['re_encrypted']++;
                }
            }
            $this->info("   connected_ad_accounts: {$manifest['tables']['connected_ad_accounts']['re_encrypted']} re-encrypted.");

            if ($isDryRun) {
                DB::rollBack();
                $manifest['status'] = 'dry_run_success';
                $this->info("=== Dry-run completed successfully! All records verified decryptable and re-encryptable ===");
            } else {
                DB::commit();
                $manifest['status'] = 'success';
                $this->info("=== Key rotation committed successfully! ===");
            }

            $manifest['execution_seconds'] = round(microtime(true) - $startTime, 3);
            $this->writeManifest($manifest);

            $this->info("Next Deployment Steps for Zero-Downtime Coexistence:");
            $this->line("1. Append previous APP_KEY to APP_PREVIOUS_KEYS in your .env: APP_PREVIOUS_KEYS={$oldKeyRaw}");
            $this->line("2. Set current APP_KEY in .env: APP_KEY={$newKeyRaw}");
            $this->line("3. Deploy updated .env and restart workers.");
            $this->line("4. Verify application health check.");
            $this->line("5. Only retire APP_PREVIOUS_KEYS after verifying that all background jobs and cached sessions have transitioned.");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $manifest['status'] = 'failed';
            $manifest['errors'][] = $e->getMessage();
            $manifest['execution_seconds'] = round(microtime(true) - $startTime, 3);
            $this->writeManifest($manifest);

            $this->error("CRITICAL KEY ROTATION FAILURE: " . $e->getMessage());
            $this->error("Transaction safely rolled back. No database records were corrupted.");
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Decrypt string with primary old key or fall back to previous keys.
     * Never silently skip failures!
     */
    protected function decryptWithFallback(
        string $ciphertext,
        Encrypter $oldEncrypter,
        array $previousEncrypters,
        string $table,
        int|string $id,
        string $column
    ): string {
        try {
            return $oldEncrypter->decryptString($ciphertext);
        } catch (Throwable $e) {
            // Attempt previous keys if configured
            foreach ($previousEncrypters as $prevEncrypter) {
                try {
                    return $prevEncrypter->decryptString($ciphertext);
                } catch (Throwable $ignore) {
                    continue;
                }
            }

            // CRITICAL: Never silently skip decryption failures!
            throw new RuntimeException("Unrecoverable decryption failure on table '{$table}', column '{$column}', ID #{$id}. Key rotation aborted to prevent data loss.");
        }
    }

    /**
     * Check if ciphertext is already encrypted with the target encrypter.
     */
    protected function canDecrypt(string $ciphertext, Encrypter $encrypter): bool
    {
        try {
            $encrypter->decryptString($ciphertext);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function writeManifest(array $manifest): void
    {
        $manifestDir = storage_path('app/key-rotation');
        if (!File::isDirectory($manifestDir)) {
            File::makeDirectory($manifestDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $manifestPath = $this->option('manifest-path') ?: "{$manifestDir}/manifest_{$timestamp}.json";

        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Recovery manifest written to: {$manifestPath}");
    }

    protected function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }
        return $key;
    }
}
