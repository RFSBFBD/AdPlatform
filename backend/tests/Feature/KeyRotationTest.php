<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeyRotationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'key_rotator@example.com'],
            ['name' => 'Key Rotator', 'password' => bcrypt('password123'), 'status' => 'active']
        );
    }

    public function test_key_rotation_re_encrypts_credentials_safely(): void
    {
        $oldKeyRaw = config('app.key');
        $newKeyRaw = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));

        $rawSecret = 'sk-super-secret-ai-token-12345';

        // 1. Create AI Config with current app key
        $aiConfig = AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'openai',
            'api_key' => $rawSecret,
            'model' => 'gpt-4o',
            'is_active' => true,
        ]);

        $dbBefore = DB::table('ai_configs')->where('id', $aiConfig->id)->value('api_key');

        // Verify standard Crypt can decrypt it
        $this->assertEquals($rawSecret, Crypt::decryptString($dbBefore));

        // 2. Run rotation command with explicit keys
        $this->artisan('app:rotate-key', [
            '--old-key' => $oldKeyRaw,
            '--new-key' => $newKeyRaw,
        ])->assertSuccessful();

        // 3. Verify that decrypting raw DB string with NEW key produces original secret
        $dbAfter = DB::table('ai_configs')->where('id', $aiConfig->id)->value('api_key');
        
        $newKey = str_starts_with($newKeyRaw, 'base64:') ? base64_decode(substr($newKeyRaw, 7)) : $newKeyRaw;
        $newEncrypter = new Encrypter($newKey, 'AES-256-CBC');
        $decryptedWithNew = $newEncrypter->decryptString($dbAfter);

        $this->assertEquals($rawSecret, $decryptedWithNew);
    }

    public function test_tampered_ciphertext_fails_hmac_integrity_check(): void
    {
        $rawSecret = 'sk-tamper-check-999';
        $aiConfig = AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'openai',
            'api_key' => $rawSecret,
            'model' => 'gpt-4o',
            'is_active' => true,
        ]);

        $dbRow = DB::table('ai_configs')->where('id', $aiConfig->id)->first();
        $payload = json_decode(base64_decode($dbRow->api_key), true);

        // Tamper with the ciphertext value
        $payload['value'] = base64_encode('tampered_encrypted_data');
        $tamperedCiphertext = base64_encode(json_encode($payload));

        DB::table('ai_configs')->where('id', $aiConfig->id)->update(['api_key' => $tamperedCiphertext]);

        // Decryption must throw exception due to HMAC mismatch
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $fresh = AiConfig::find($aiConfig->id);
        $unreachable = $fresh->api_key; // triggers decrypt cast
    }

    public function test_dry_run_validates_without_modifying_database_records(): void
    {
        $oldKeyRaw = config('app.key');
        $newKeyRaw = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));
        $rawSecret = 'sk-dry-run-secret-12345';

        $aiConfig = AiConfig::create([
            'user_id' => $this->user->id,
            'provider' => 'anthropic',
            'api_key' => $rawSecret,
            'model' => 'claude-3-5-sonnet',
            'is_active' => true,
        ]);

        $dbBefore = DB::table('ai_configs')->where('id', $aiConfig->id)->value('api_key');

        $manifestPath = storage_path('app/key-rotation/dry_run_test_manifest.json');

        $this->artisan('app:rotate-key', [
            '--old-key' => $oldKeyRaw,
            '--new-key' => $newKeyRaw,
            '--dry-run' => true,
            '--manifest-path' => $manifestPath,
        ])->assertSuccessful();

        $dbAfter = DB::table('ai_configs')->where('id', $aiConfig->id)->value('api_key');

        // In dry run mode, ciphertext in database must remain UNCHANGED
        $this->assertEquals($dbBefore, $dbAfter);

        // Verify manifest was created with dry_run_success status
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertEquals('dry_run_success', $manifest['status']);
        $this->assertTrue($manifest['is_dry_run']);

        if (file_exists($manifestPath)) unlink($manifestPath);
    }

    public function test_failed_decryption_aborts_rotation_safely_without_data_corruption(): void
    {
        // Insert a corrupt, un-decryptable record into ai_configs
        $corruptRowId = DB::table('ai_configs')->insertGetId([
            'user_id' => $this->user->id,
            'provider' => 'gemini',
            'api_key' => 'corrupted_invalid_base64_data',
            'model' => 'gemini-1.5-pro',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldKeyRaw = config('app.key');
        $newKeyRaw = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));

        // Rotation command must fail (exit code 1) rather than silently skipping
        $this->artisan('app:rotate-key', [
            '--old-key' => $oldKeyRaw,
            '--new-key' => $newKeyRaw,
        ])->assertFailed();

        // Verify corrupted record was not deleted or altered
        $stillThere = DB::table('ai_configs')->where('id', $corruptRowId)->value('api_key');
        $this->assertEquals('corrupted_invalid_base64_data', $stillThere);
    }
}
