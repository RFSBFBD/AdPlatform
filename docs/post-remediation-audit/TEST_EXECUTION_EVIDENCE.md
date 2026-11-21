# INDEPENDENT TEST EXECUTION EVIDENCE

**Project:** Advertising Management Platform  
**Auditors:** Independent Principal Security Engineer & Senior QA Auditor  
**Audit Date:** 2026-11-19  
**Execution Environment:** Windows Host + Docker Desktop Linux Containers (PHP 8.3 FPM, Node.js 22, PostgreSQL 16, Redis 7)  

---

## 1. Backend Test Suite Execution

- **Command:** `docker compose exec -T backend php artisan test`
- **Exit Code:** `0`
- **Total Tests:** 51 passed
- **Total Assertions:** 366 passed
- **Failed Tests:** 0
- **Skipped / Incomplete Tests:** 0
- **Total Duration:** 65.92s

### Comprehensive Suite Breakdown

```text
   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\AiTest
  ✓ unauthenticated requests are rejected                               10.27s  
  ✓ byok configuration encrypts key and masks in response                1.40s  
  ✓ generate ad copy enforces limits and audits usage                    0.63s  
  ✓ analyze performance returns recommendations                          0.53s  
  ✓ anthropic completion executes http request                           1.54s  
  ✓ gemini completion executes http request                              0.44s  
  ✓ ai upstream failure returns structured error and does not consume t… 2.09s  

   PASS  Tests\Feature\AnalyticsTest
  ✓ unauthenticated requests are rejected                                0.47s  
  ✓ overview calculates aggregated and blended metrics                   0.63s  
  ✓ overview filters by provider                                         0.44s  
  ✓ campaigns endpoint returns paginated list with metrics               0.51s  
  ✓ campaign details endpoint                                            0.51s  

   PASS  Tests\Feature\AuthTest
  ✓ user can login with valid credentials                                4.79s  
  ✓ user cannot login with invalid password                              0.83s  
  ✓ unauthenticated request to me is rejected                            0.58s  
  ✓ authenticated user can fetch profile                                 0.65s  
  ✓ viewer cannot access user management                                 0.73s  
  ✓ owner can invite new member                                          0.69s  

   PASS  Tests\Feature\BillingTest
  ✓ unauthenticated requests are rejected                                0.80s  
  ✓ billing summary returns aggregated balances and payment methods      0.56s  
  ✓ billing invoices returns paginated list with receipt url             0.49s  

   PASS  Tests\Feature\DeveloperConfigTest
  ✓ unauthorized user cannot read developer configs                      0.53s  
  ✓ analyst cannot access developer configs                              0.60s  
  ✓ owner can configure meta credentials and secrets are encrypted       0.68s  

   PASS  Tests\Feature\ExampleTest
  ✓ the application returns a successful response                        1.47s  

   PASS  Tests\Feature\HealthCheckTest
  ✓ health check endpoint returns healthy                                0.62s  

   PASS  Tests\Feature\IntegrationTest
  ✓ meta oauth redirect generates valid url and state                    0.71s  
  ✓ google oauth redirect generates valid url and scopes                 0.47s  
  ✓ callback rejects invalid or expired state                            0.44s  
  ✓ connecting ad accounts encrypts tokens and masks them                0.50s  
  ✓ connecting account already owned by another user is forbidden        0.49s  

   PASS  Tests\Feature\LookerStudioTest
  ✓ unauthenticated requests are rejected                                0.53s  
  ✓ schema request returns looker dimensions and metrics                 0.39s  
  ✓ data request returns aggregated rows for looker                      0.40s  
  ✓ multi tenant isolation prevents user from seeing another users metr… 0.41s  

   PASS  Tests\Feature\SyncEngineTest
  ✓ manual sync trigger queues job                                       1.05s  
  ✓ sync engine normalizes data calculates metrics and audits            1.22s  
  ✓ sync engine prevents concurrent runs with redis lock                 0.62s  
  ✓ sync engine is idempotent                                            0.55s  
  ✓ get sync status and history endpoints                                0.41s  

   PASS  Tests\Feature\TenancyIsolationTest
  ✓ user cannot list ad accounts belonging to another user               0.55s  
  ✓ analytics overview is strictly scoped to user connected accounts     0.53s  
  ✓ campaigns endpoint only returns campaigns for authenticated user     0.52s  
  ✓ campaign details rejects access to another users campaign            0.57s  
  ✓ billing summary is isolated per user                                 0.72s  
  ✓ billing invoices are isolated per user                               0.59s  
  ✓ viewer role cannot disconnect an ad account sec 002                  0.51s  
  ✓ user cannot disconnect an ad account belonging to another user       0.51s  
  ✓ owner can disconnect own ad account                                  0.52s  
  ✓ regular user cannot disconnect or sync account with null user id     0.53s  

  Tests:    51 passed (366 assertions)
  Duration: 65.92s
```

---

## 2. Frontend Test Suite Execution

- **Command:** `cd frontend; npm test -- --watch=false`
- **Exit Code:** `0`
- **Total Test Files:** 4 passed
- **Total Tests:** 10 passed
- **Duration:** 6.56s

### Test Files Breakdown

| Test File | Tests Passed | Duration |
|---|---|---|
| `src/app/core/services/theme.service.spec.ts` | 4 | 51ms |
| `src/app/app.spec.ts` | 1 | 82ms |
| `src/app/shared/components/button/button.component.spec.ts` | 2 | 195ms |
| `src/app/features/team/team.component.spec.ts` | 3 | 302ms |

---

## 3. Frontend Production Build Verification

- **Command:** `cd frontend; npm run build`
- **Exit Code:** `0`
- **Build Output Directory:** `frontend/dist/frontend`
- **Initial Total Raw Size:** 316.68 kB (85.62 kB estimated transfer)
- **Lazy Chunk Output:**
  - `main-5WYGHSP2.js`: 313.77 kB
  - `ai-center-component`: 18.26 kB
  - `accounts-component`: 12.42 kB
  - `campaigns-component`: 11.61 kB
  - `developer-settings-component`: 11.12 kB
  - `dashboard-component`: 9.68 kB
  - `team-component`: 9.61 kB
  - `billing-component`: 9.46 kB
  - `login-component`: 5.67 kB
- **Compilation Errors / Warnings:** 0

---

## 4. Production Docker Image Build Verification

- **Command:** `docker compose -f docker-compose.prod.yml build`
- **Exit Code:** `0`
- **Built Images:**
  - `ad_platform-backend:latest`
  - `ad_platform-queue-worker:latest`
  - `ad_platform-scheduler:latest`
- **Duration:** 28.5s
