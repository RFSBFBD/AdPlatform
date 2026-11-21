# Technical Audit: Detailed Engineering Findings

**Project:** Advertising Management Platform  
**Audit Date:** 2026-11-18  

---

## Index of Findings

| Finding ID | Severity | Category | Title | Status |
|---|---|---|---|---|
| `SEC-001` | **HIGH** | Security / Authorization | Missing Tenancy & User Scoping on Analytics & Ad Accounts | **FAIL** |
| `SEC-002` | **HIGH** | Security / Authorization | Broken Access Control (BOP) on Account Disconnection | **FAIL** |
| `SEC-003` | **LOW** | Security / Infrastructure | Docker Local Database & Cache Ports Exposed on All Interfaces | **PARTIAL** |
| `INT-001` | **MEDIUM** | Integrations | Advertising Providers Verified Only via Mocks, Not Live APIs | **BLOCKED** |
| `INT-002` | **MEDIUM** | Integrations / Data | Sync Engine and Adapters Ingest at Campaign-Level Only | **PARTIAL** |
| `INT-003` | **LOW** | Integrations | Google Ads Adapter Uses Default Placeholder Customer Metadata | **PARTIAL** |
| `AI-001`  | **MEDIUM** | AI Integration | Anthropic Claude & Gemini Completions Fall Back to Hardcoded Array | **PARTIAL** |
| `AI-002`  | **LOW** | AI Integration | Connection Handshake Only Tests Model Listing | **PARTIAL** |
| `DEV-001` | **HIGH** | DevOps / Docker | Production Compose Build Context Inconsistency | **FAIL** |
| `DEV-002` | **LOW** | DevOps / Docker | Soft Healthcheck in Production PHP-FPM Backend | **PARTIAL** |
| `FE-001`  | **MEDIUM** | Frontend / UX | Team Management Component Contains Hardcoded Static Mockup | **PARTIAL** |
| `FE-002`  | **LOW** | Frontend / QA | Low Unit Test Coverage for Feature Components | **PARTIAL** |
| `DATA-001`| **MEDIUM** | Data Engineering | Blended Multi-Currency Aggregation Disregards FX Conversions | **PARTIAL** |
| `DATA-002`| **LOW** | Data Engineering | Incremental Sync Attribution Window (3 Days) Misses Delayed Conversions | **PARTIAL** |
| `BI-001`  | **LOW** | Business Intelligence | Looker Studio Connector Not Deployed to Google Apps Script | **BLOCKED** |
| `BILL-001`| **LOW** | Billing | Automated Provider Invoice Ingestion Not Implemented | **PARTIAL** |

---

## Detailed Findings

### Finding SEC-001: Missing Tenancy & User Scoping on Analytics & Ad Accounts
- **Severity:** HIGH
- **Category:** Security / Authorization / Data Isolation
- **Status:** FAIL
- **File Paths:**
  - `backend/app/Http/Controllers/Api/V1/AnalyticsController.php` (lines 30–48, 142–175)
  - `backend/app/Http/Controllers/Api/V1/IntegrationController.php` (lines 179–193)
  - `backend/app/Http/Controllers/Api/V1/BillingController.php` (lines 20–42, 67–88)
- **Relevant Methods:**
  - `AnalyticsController::overview`
  - `AnalyticsController::campaigns`
  - `IntegrationController::listAccounts`
  - `BillingController::summary`
- **Evidence:**
  In `AnalyticsController.php`:
  ```php
  $query = PerformanceMetric::query()->whereBetween('date', [$startDate, $endDate]);
  ```
  In `IntegrationController.php`:
  ```php
  public function listAccounts(Request $request): JsonResponse {
      $query = ConnectedAdAccount::query();
      ...
      $accounts = $query->orderBy('account_name', 'asc')->get();
  }
  ```
- **Actual Behavior:** Any authenticated user (even with Viewer or Analyst role) can query all connected accounts, campaigns, and financial metrics belonging to any other user or organization in the platform.
- **Expected Behavior:** Queries should be scoped to the authenticated user's organization or assigned accounts (e.g. `where('user_id', $request->user()->id)` or via a tenant scope).
- **Business Impact:** Critical privacy violation and cross-tenant data leak in multi-user or multi-client environments.
- **Recommended Solution:** Introduce global query scopes or middleware restricting account queries to accounts assigned to the authenticated user's tenant/organization.

---

### Finding SEC-002: Broken Access Control on Account Disconnection
- **Severity:** HIGH
- **Category:** Security / Authorization
- **Status:** FAIL
- **File Path:** `backend/app/Http/Controllers/Api/V1/IntegrationController.php` (lines 198–213)
- **Relevant Method:** `IntegrationController::disconnectAccount`
- **Evidence:**
  ```php
  public function disconnectAccount(Request $request, int $id): JsonResponse {
      $account = ConnectedAdAccount::findOrFail($id);
      ...
      $account->delete();
  }
  ```
  Route definition in `backend/routes/api.php`:
  ```php
  Route::delete('/{id}', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'disconnectAccount']);
  ```
- **Actual Behavior:** No role or permission check is attached to this endpoint. Any authenticated user, including `Viewer` or `Analyst`, can send a `DELETE /api/v1/ad-accounts/{id}` request and permanently disconnect an ad account.
- **Expected Behavior:** Restrict deletion to users with `permission:manage-integrations` or role `Owner`/`Admin`.
- **Business Impact:** Unauthorized users can disrupt advertising data pipelines and delete critical connections.
- **Recommended Solution:** Add `middleware('permission:manage-integrations')` or verify role authorization before deletion.

---

### Finding INT-001: Advertising Providers Verified Only via Mocks, Not Live APIs
- **Severity:** MEDIUM
- **Category:** Integrations / Third-Party APIs
- **Status:** BLOCKED_BY_CREDENTIALS
- **File Paths:**
  - `backend/app/Services/Advertising/Adapters/MetaAdsAdapter.php`
  - `backend/app/Services/Advertising/Adapters/GoogleAdsAdapter.php`
  - `backend/app/Services/Advertising/Adapters/TikTokAdsAdapter.php`
  - `backend/tests/Feature/IntegrationTest.php`
- **Evidence:**
  `IntegrationTest.php` uses `Http::fake()` to simulate OAuth code exchange and account discovery responses. No live requests have been made to:
  - Meta Graph API (`graph.facebook.com/v21.0`)
  - Google Ads API (`googleads.googleapis.com/v18`)
  - TikTok Business API (`business-api.tiktok.com/open_api/v1.3`)
- **Actual Behavior:** The adapters correctly implement official HTTP schemas, endpoints, and headers, but live connectivity has not been proven against real provider accounts because user credentials are not configured in the host environment.
- **Expected Behavior:** Staging verification against live developer sandbox or test accounts.
- **Business Impact:** Live OAuth edge cases (e.g. redirect URI domain whitelist mismatches, app review permissions, developer token approval delays) may emerge when first connecting live accounts.
- **Recommended Solution:** Require the user to configure verified developer sandbox credentials in Developer Settings to execute an end-to-end live handshake test.

---

### Finding INT-002: Ingestion Hierarchy Limited to Campaign Level
- **Severity:** MEDIUM
- **Category:** Integrations / Data Engineering
- **Status:** PARTIAL
- **File Paths:**
  - `backend/app/Services/Advertising/Sync/AdDataSyncEngine.php` (lines 145–171)
  - `backend/database/migrations/2026_11_18_153000_create_advertising_data_tables.php`
- **Evidence:**
  In `AdDataSyncEngine.php`:
  ```php
  PerformanceMetric::updateOrCreate(
      [ ... ],
      [
          'ad_set_id' => null,
          'ad_id' => null,
          ...
      ]
  );
  ```
- **Actual Behavior:** Although tables for `ad_sets` and `ads` exist in the database, the adapters only query `/campaigns` and `/insights?level=campaign`. The sub-campaign hierarchy (ad groups and individual creative ads) is not ingested.
- **Expected Behavior:** Ingest the complete 3-tier hierarchy (Campaign -> Ad Set / Ad Group -> Ad Creative) as designed in `MASTER_PROJECT_REFERENCE.md`.
- **Business Impact:** Users cannot view creative-level ad performance or drill down to ad-set targeting metrics in the dashboard.
- **Recommended Solution:** Extend provider adapters to fetch AdSets and Ads and link their primary keys in `performance_metrics`.

---

### Finding AI-001: Anthropic Claude & Gemini Completions Fall Back to Hardcoded Array
- **Severity:** MEDIUM
- **Category:** AI Integration
- **Status:** PARTIAL
- **File Path:** `backend/app/Services/Ai/AiManager.php` (lines 120–166)
- **Relevant Method:** `AiManager::executeAiCompletion`
- **Evidence:**
  ```php
  if (!empty($config->api_key) && !app()->environment('testing')) {
      if ($config->provider === 'openai') {
          // HTTP POST to api.openai.com/v1/chat/completions
      }
  } else {
      // Deterministic fallback array
  }
  ```
- **Actual Behavior:** When a user configures Anthropic Claude or Google Gemini, `executeAiCompletion` skips the OpenAI block and falls straight into the fallback array, returning pre-programmed text rather than making an API call to Anthropic or Gemini.
- **Expected Behavior:** Implement native API calls for Anthropic (`https://api.anthropic.com/v1/messages`) and Google Gemini (`https://generativelanguage.googleapis.com/v1beta/models/...:generateContent`).
- **Business Impact:** Users configuring Anthropic or Gemini keys receive simulated fallback results rather than real model output.
- **Recommended Solution:** Implement `executeAnthropicCompletion` and `executeGeminiCompletion` methods in `AiManager`.

---

### Finding DEV-001: Production Compose Build Context Inconsistency
- **Severity:** HIGH
- **Category:** DevOps / Docker
- **Status:** FAIL
- **File Path:** `docker-compose.prod.yml` (lines 23–27)
- **Evidence:**
  ```yaml
  backend:
    build:
      context: ./backend
      dockerfile: ../docker/php/Dockerfile
  ```
  Inside `docker/php/Dockerfile`:
  ```dockerfile
  COPY local.ini /usr/local/etc/php/conf.d/local.ini
  ```
- **Actual Behavior:** When `docker compose -f docker-compose.prod.yml build` is executed, Docker sets the build context to `./backend`. The `COPY local.ini` instruction looks for `./backend/local.ini`, which does not exist, causing the production build to abort with a fatal file-not-found error.
- **Expected Behavior:** The build context should be `./` or `./docker/php` with correct relative paths so `local.ini` is accessible.
- **Business Impact:** Direct execution of `docker-compose.prod.yml` will fail on a fresh production server.
- **Recommended Solution:** Set `context: .` and `dockerfile: docker/php/Dockerfile` in `docker-compose.prod.yml`, adjusting `COPY docker/php/local.ini ...`.

---

### Finding DEV-002: Soft Healthcheck in Production Backend
- **Severity:** LOW
- **Category:** DevOps / Reliability
- **Status:** PARTIAL
- **File Path:** `docker-compose.prod.yml` (lines 51–56)
- **Evidence:**
  ```yaml
  healthcheck:
    test: ["CMD-SHELL", "php-fpm-healthcheck || exit 0"]
  ```
- **Actual Behavior:** Because `php-fpm-healthcheck` is not installed into the PHP container and the test ends with `|| exit 0`, the health check always succeeds regardless of whether PHP-FPM is responsive or frozen.
- **Expected Behavior:** Either install `fcgi` and a functional `php-fpm-healthcheck` binary, or check PHP-FPM via socket/TCP port test.
- **Business Impact:** In production, a crashed or hung PHP-FPM process will still be reported as healthy, preventing container auto-restart.
- **Recommended Solution:** Configure a robust healthcheck using `SCRIPT_NAME=/health cgi-fcgi -bind -connect 127.0.0.1:9000` or an HTTP health endpoint.

---

### Finding FE-001: Team Management Component Contains Hardcoded Static Mockup
- **Severity:** MEDIUM
- **Category:** Frontend / Architecture
- **Status:** PARTIAL
- **File Path:** `frontend/src/app/features/team/team.component.ts` (lines 32–47, 150–157)
- **Evidence:**
  ```html
  <span class="name">John Doe</span>
  <span class="email">john@example.com</span>
  ...
  <app-badge variant="info">Owner</app-badge>
  ```
  ```typescript
  export class TeamFeatureComponent {}
  ```
- **Actual Behavior:** The component is a static HTML template with hardcoded data. It injects no services, calls no HTTP endpoints, and has non-functional buttons for "+ Invite Member" and "Edit".
- **Expected Behavior:** Inject `HttpClient` or a `UserService`, fetch users from `/api/v1/users`, display real team members, and open an invitation modal bound to `POST /api/v1/users/invite`.
- **Business Impact:** Administrators cannot view or manage team members from the frontend UI.
- **Recommended Solution:** Implement reactive signals, inject `HttpClient`, and bind table and invitation modal to backend `UserController`.

---

### Finding DATA-001: Blended Multi-Currency Aggregation Disregards FX Conversions
- **Severity:** MEDIUM
- **Category:** Data Engineering / Financial Reporting
- **Status:** PARTIAL
- **File Path:** `backend/app/Http/Controllers/Api/V1/AnalyticsController.php` (lines 42–60)
- **Evidence:**
  ```php
  $totals = (clone $query)->select([
      DB::raw('COALESCE(SUM(spend), 0) as total_spend'),
      ...
  ])->first();
  ```
- **Actual Behavior:** If a user connects a Meta account denominated in EUR and a Google account denominated in USD, `SUM(spend)` calculates an arithmetic sum of the numbers directly without converting currencies.
- **Expected Behavior:** Either display spend isolated by account currency or introduce a base currency conversion rate service (e.g. converting to USD or organization base currency).
- **Business Impact:** Distorted financial dashboards when managing multi-currency advertising portfolios.
- **Recommended Solution:** Add currency filtering to the overview query and introduce an exchange-rate conversion service for blended totals.
