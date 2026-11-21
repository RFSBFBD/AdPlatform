<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupVerificationTest extends TestCase
{
    public function test_genuine_backup_and_restore_verification_passes(): void
    {
        // Record pre-verification table count
        $initialTableCount = count(DB::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
        ));

        $exitCode = Artisan::call('db:verify-backup');
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode, "Artisan db:verify-backup should exit with 0. Output:\n{$output}");
        $this->assertStringContainsString('Genuine PostgreSQL Backup & Restore Verification: PASSED', $output);
        $this->assertStringContainsString('All parity checks passed', $output);

        // Ensure primary database was never modified
        $postTableCount = count(DB::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
        ));
        $this->assertEquals($initialTableCount, $postTableCount, "Primary database table count must remain identical.");

        // Ensure no temporary verification databases were left behind
        $lingeringDbs = DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'backup_verify_test_%'");
        $this->assertEmpty($lingeringDbs, "All temporary verification databases must be cleaned up.");
    }
}
