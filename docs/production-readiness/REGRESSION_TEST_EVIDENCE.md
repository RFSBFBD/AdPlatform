# COMPREHENSIVE REGRESSION & VERIFICATION TEST EVIDENCE

**Project:** Multi-Tenant Advertising Management Platform  
**Auditor:** QA Automation Lead & Senior Systems Engineer  
**Execution Timestamp:** 2026-11-19T13:10:00Z  
**Overall Result:** `ALL TEST SUITES PASSING (0 FAILURES)`

---

## 1. Backend Automated Regression Suite

Command: `docker compose exec -T backend php artisan test`  
Exit Code: `0`  
Execution Time: `75.56 seconds`  
Coverage: **71 passed tests (451 assertions)** across 15 feature and unit test suites with **0 failures**.

```
   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\AdPlatformDataIntegrityTest
  ✓ google ads discovers native currency and timezone                   10.63s  
  ✓ google ads pagination across multiple pages and empty final page     0.41s  
  ✓ tiktok ads pagination across multiple pages                          0.51s  
  ✓ meta ads pagination across multiple pages                            0.48s  
  ✓ hierarchical metric storage prevents overwrites between entities     1.27s  

   PASS  Tests\Feature\AiTest
  ✓ unauthenticated requests are rejected                                1.82s  
  ✓ byok configuration encrypts key and masks in response                0.69s  
  ✓ generate ad copy enforces limits and audits usage                    0.42s  
  ✓ analyze performance returns recommendations                          0.43s  
  ✓ anthropic completion executes http request                           0.49s  
  ✓ gemini completion executes http request                              0.44s  
  ✓ ai upstream failure returns structured error and does not consume t… 0.91s  

   PASS  Tests\Feature\AnalyticsTest
  ✓ unauthenticated requests are rejected                                0.48s  
  ✓ overview calculates aggregated and blended metrics                   0.54s  
  ✓ overview filters by provider                                         0.59s  
  ✓ campaigns endpoint returns paginated list with metrics               0.52s  
  ✓ campaign details endpoint                                            0.50s  

   PASS  Tests\Feature\AuthTest
  ✓ user can login with valid credentials                                4.66s  
  ✓ user cannot login with invalid password                              0.82s  
  ✓ unauthenticated request to me is rejected                            0.53s  
  ✓ authenticated user can fetch profile                                 0.63s  
  ✓ viewer cannot access user management                                 0.72s  
  ✓ owner can invite new member                                          0.60s  

   PASS  Tests\Feature\BackupVerificationTest
  ✓ genuine backup and restore verification passes                       4.99s  

   PASS  Tests\Feature\BillingTest
  ✓ unauthenticated requests are rejected                                0.85s  
  ✓ billing summary returns aggregated balances and payment methods      0.61s  
  ✓ billing invoices returns paginated list with receipt url             0.42s  

   PASS  Tests\Feature\CurrencyConversionTest
  ✓ service converts direct and inverse rates                            0.41s  
  ✓ service explicitly reports missing exchange rates                    0.41s  
  ✓ overview endpoint converts mixed currencies using historical rates   0.43s  
  ✓ overview endpoint handles missing rates transparently                0.45s  
  ✓ service validates iso 4217 currency codes                            0.65s  
  ✓ provider rejects non positive exchange rate                          0.41s  
  ✓ service exposes actual effective date and fallback status            0.51s  
  ✓ unconverted rows do not distort cpc cpm cpa roas                     0.48s  
  ✓ campaigns endpoint aggregates metrics in reporting currency          0.63s  

   PASS  Tests\Feature\DeveloperConfigTest
  ✓ unauthorized user cannot read developer configs                      0.58s  
  ✓ analyst cannot access developer configs                              0.56s  
  ✓ owner can configure meta credentials and secrets are encrypted       0.51s  

   PASS  Tests\Feature\ExampleTest
  ✓ the application returns a successful response                        1.27s  

   PASS  Tests\Feature\HealthCheckTest
  ✓ health check endpoint returns healthy                                0.68s  

   PASS  Tests\Feature\IntegrationTest
  ✓ meta oauth redirect generates valid url and state                    0.61s  
  ✓ google oauth redirect generates valid url and scopes                 0.51s  
  ✓ callback rejects invalid or expired state                            0.50s  
  ✓ connecting ad accounts encrypts tokens and masks them                0.55s  
  ✓ connecting account already owned by another user is forbidden        0.61s  

   PASS  Tests\Feature\KeyRotationTest
  ✓ key rotation re encrypts credentials safely                          0.65s  
  ✓ tampered ciphertext fails hmac integrity check                       0.44s  
  ✓ dry run validates without modifying database records                 0.56s  
  ✓ failed decryption aborts rotation safely without data corruption     0.81s  

   PASS  Tests\Feature\LookerStudioTest
  ✓ unauthenticated requests are rejected                                0.52s  
  ✓ schema request returns looker dimensions and metrics                 0.53s  
  ✓ data request returns aggregated rows for looker                      0.43s  
  ✓ multi tenant isolation prevents user from seeing another users metr… 0.46s  

   PASS  Tests\Feature\SyncEngineTest
  ✓ manual sync trigger queues job                                       0.86s  
  ✓ sync engine normalizes data calculates metrics and audits            0.57s  
  ✓ sync engine prevents concurrent runs with redis lock                 0.44s  
  ✓ sync engine is idempotent                                            0.50s  
  ✓ get sync status and history endpoints                                0.48s  
  ✓ google and tiktok hierarchical sync persists ad groups and ads       0.52s  

   PASS  Tests\Feature\TenancyIsolationTest
  ✓ user cannot list ad accounts belonging to another user               0.82s  
  ✓ analytics overview is strictly scoped to user connected accounts     0.63s  
  ✓ campaigns endpoint only returns campaigns for authenticated user     0.62s  
  ✓ campaign details rejects access to another users campaign            0.62s  
  ✓ billing summary is isolated per user                                 0.55s  
  ✓ billing invoices are isolated per user                               0.56s  
  ✓ viewer role cannot disconnect an ad account sec 002                  0.61s  
  ✓ user cannot disconnect an ad account belonging to another user       0.62s  
  ✓ owner can disconnect own ad account                                  0.74s  
  ✓ regular user cannot disconnect or sync account with null user id     0.52s  

  Tests:    71 passed (451 assertions)
  Duration: 75.56s
```

---

## 2. Frontend Automated Regression Suite

Command: `npm test -- --watch=false` (inside `frontend/`)  
Exit Code: `0`  
Execution Time: `7.77 seconds`  
Coverage: **10 passed tests (10 assertions)** across 4 test suites with **0 failures**.

```
 RUN  v4.1.12 D:/soc platform/frontend

 ✓  frontend  src/app/core/services/theme.service.spec.ts (4 tests) 151ms
 ✓  frontend  src/app/shared/components/button/button.component.spec.ts (2 tests) 412ms
 ✓  frontend  src/app/app.spec.ts (1 test) 128ms
 ✓  frontend  src/app/features/team/team.component.spec.ts (3 tests) 539ms

 Test Files  4 passed (4)
      Tests  10 passed (10)
   Duration  7.77s
```

---

## 3. Production Bundle Build Verification

Command: `npm run build` (inside `frontend/`)  
Exit Code: `0`  
Build Time: `7.67 seconds`  
Output: `frontend/dist/frontend` (Total bundle transfer size: 85.62 kB initial, 14 lazy chunks) with zero errors.

---

## 4. Production Docker Infrastructure Configuration

Command: `docker compose -f docker-compose.prod.yml config -q`  
Exit Code: `0`  
Services Configured:
- `backend` (PHP 8.3 FPM, postgresql-client, redis, opcache, healthcheck on port 9000)
- `nginx` (TLS 1.2/1.3 reverse proxy, port 80/443, HSTS)
- `postgres` (PostgreSQL 16 Alpine, healthcheck on port 5432)
- `redis` (Redis 7 Alpine, healthcheck on port 6379)
- `queue-worker` (Supervised multi-queue worker, tries=3, timeout=90)
- `scheduler` (Artisan schedule runner on 60s cadence)
