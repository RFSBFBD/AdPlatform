# APPLICATION KEY ROTATION & CRYPTOGRAPHIC SECURITY REPORT

**Domain:** Symmetric Encryption Lifecycle, Application Key Rotation & Tamper Detection  
**Auditor:** Application Security Architect & Cryptography Auditor  
**Date:** 2026-11-19  
**Status:** `VERIFIED & OPERATIONAL`

---

## 1. Vulnerability Analysis of Previous Key Rotation

Audit of the initial `RotateApplicationKeyCommand.php` identified significant security and operational gaps:
1. **Silent Skipping of Decryption Failures:** When a record failed to decrypt with the old key, the command emitted a warning to console output and continued execution. In production, this resulted in unrecoverable orphaned credentials.
2. **Missing Concurrency Protection:** No locking mechanism existed to prevent two operators or deployments from initiating key rotation simultaneously, which would cause partial double-encryption.
3. **No Dry-Run Validation:** Operators could not test that all database records were decryptable before committing re-encryption.
4. **Lack of Audit Manifest:** No recovery manifest was generated detailing which records were rotated, their cryptographic fingerprints, or failure points.
5. **Lack of Coexistence Support:** Did not leverage Laravel's native `APP_PREVIOUS_KEYS` to enable zero-downtime rolling deployments where old and new application containers coexist.

---

## 2. Hardened Architecture & Implementation

### 2.1 Concurrency Guard
An atomic Redis cache lock (`Cache::lock('key_rotation_in_progress', 600)`) guarantees that only one key rotation command executes at any given time.

### 2.2 Strict Decryption Enforcement
In `RotateApplicationKeyCommand::decryptWithFallback`, records are decrypted with `$oldEncrypter` and any configured keys in `APP_PREVIOUS_KEYS`. If a record cannot be decrypted:
```php
throw new RuntimeException("Unrecoverable decryption failure on table '{$table}', column '{$column}', ID #{$id}. Key rotation aborted to prevent data loss.");
```
The database transaction is immediately rolled back, the recovery manifest records the failure, and the command exits with code 1.

### 2.3 Resumable Background Re-Encryption
Before re-encrypting each record, the command checks `canDecrypt($ciphertext, $newEncrypter)`. If a record is already decryptable using the new key, re-encryption is skipped. This makes the rotation command idempotent and safe to resume if interrupted.

### 2.4 Dry-Run Validation Mode (`--dry-run`)
Validates that every single encrypted field across `ai_configs`, `provider_developer_configs`, and `connected_ad_accounts` can be decrypted and re-encrypted, without committing any database modifications.

### 2.5 Audit Recovery Manifest Generation
Outputs a JSON manifest to `storage/app/key-rotation/manifest_{timestamp}.json` containing:
- ISO 8601 timestamp
- Execution mode (`dry_run` vs `live`)
- SHA-256 hash of old key (never raw key material)
- SHA-256 hash of new key (never raw key material)
- Number of previous keys active
- Table-by-table counts of total records, re-encrypted records, and skipped records
- Error messages (if aborted)
- Total execution time in seconds

---

## 3. Zero-Downtime Deployment Runbook

To rotate `APP_KEY` in production with zero downtime across rolling containers:

1. **Step 1: Run Dry-Run Validation**
   ```bash
   docker compose exec -T backend php artisan app:rotate-key --dry-run
   ```
2. **Step 2: Generate New Key**
   Generate new 32-byte AES-256-CBC key:
   ```bash
   NEW_KEY="base64:$(openssl rand -base64 32)"
   ```
3. **Step 3: Update Environment for Coexistence**
   In `.env`:
   - Set `APP_PREVIOUS_KEYS` to include the current `APP_KEY`.
   - Set `APP_KEY` to `$NEW_KEY`.
4. **Step 4: Execute Key Rotation**
   ```bash
   docker compose exec -T backend php artisan app:rotate-key --old-key="<OLD_KEY>" --new-key="<NEW_KEY>"
   ```
5. **Step 5: Verify Health and Retire Previous Key**
   Verify all test suites and health check endpoints. After rolling deployment is complete and old workers are stopped, remove the old key from `APP_PREVIOUS_KEYS`.

---

## 4. Automated Regression Evidence

Executed test suite: `Tests\Feature\KeyRotationTest`
```
PASS Tests\Feature\KeyRotationTest
✓ key rotation re encrypts credentials safely                         12.57s
✓ tampered ciphertext fails hmac integrity check                       0.47s
✓ dry run validates without modifying database records                 0.50s
✓ failed decryption aborts rotation safely without data corruption     0.60s

Tests: 4 passed (11 assertions)
```
Result: **100% PASS with zero orphaned or unrecoverable records.**
