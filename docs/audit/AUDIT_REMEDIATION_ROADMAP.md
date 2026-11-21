# Technical Audit: Prioritized Remediation Roadmap

**Project:** Advertising Management Platform  
**Audit Date:** 2026-11-18  

---

## 1. Roadmap Overview & Priority Classification

This roadmap defines the necessary remediation steps before the platform can transition from **CONDITIONALLY READY** to **READY FOR STAGING** and subsequently **PRODUCTION CANDIDATE**.

- **Priority 0 (P0) — Production Blockers & Critical Security:** Must be resolved before opening staging access to any non-developer users.
- **Priority 1 (P1) — Major Functional Defects:** Core workflows that are incomplete or contain architectural mismatches.
- **Priority 2 (P2) — Integration Gaps & Granularity:** Expanding adapters to live APIs and deeper entities.
- **Priority 3 (P3) — Performance & Quality Enhancements:** Multi-currency FX, test coverage, and healthcheck tuning.

---

## 2. Prioritized Action Items

### Priority 0: Production Blockers & Critical Security

#### Item REM-001: Tenancy and User Scoping across Analytics & Accounts
- **Related Issue:** `SEC-001`
- **Required Change:** Add user/tenant scoping to `ConnectedAdAccount`, `PerformanceMetric`, `Campaign`, and `BillingInvoice` queries across `AnalyticsController`, `IntegrationController`, and `BillingController`.
- **Dependencies:** User authentication.
- **Risk:** High (cross-tenant data leakage if unresolved).
- **Estimated Complexity:** Medium.
- **Suggested Order:** 1.
- **Acceptance Criteria:** A user with assigned accounts can only retrieve their own accounts and metrics. Requests with an ID belonging to another user return HTTP 404 or 403.

#### Item REM-002: Role-Based Authorization on Account Deletion & Mutations
- **Related Issue:** `SEC-002`
- **Required Change:** Protect `DELETE /api/v1/ad-accounts/{id}` and `POST /api/v1/ad-accounts/{id}/sync` with permission middleware (e.g. `permission:manage-integrations` or check Owner/Admin role).
- **Dependencies:** Spatie Permissions.
- **Risk:** High (unauthorized account deletion by Viewers).
- **Estimated Complexity:** Small.
- **Suggested Order:** 2.
- **Acceptance Criteria:** `Viewer` and `Analyst` users receive HTTP 403 Forbidden when attempting to disconnect an account or trigger a manual sync.

#### Item REM-003: Rectify Production Docker Compose Build Context
- **Related Issue:** `DEV-001`
- **Required Change:** Update `docker-compose.prod.yml` to set build context to `./` with `dockerfile: docker/php/Dockerfile` (or adjust `Dockerfile` COPY path) so `local.ini` is properly located during production image builds.
- **Dependencies:** None.
- **Risk:** High (production deployment build fails).
- **Estimated Complexity:** Small.
- **Suggested Order:** 3.
- **Acceptance Criteria:** Running `docker compose -f docker-compose.prod.yml build` succeeds without file-not-found errors.

---

### Priority 1: Major Functional Defects

#### Item REM-004: Wire Angular Team Management Component to Backend API
- **Related Issue:** `FE-001`
- **Required Change:** Replace static "John Doe" mockup in `team.component.ts` with real reactive data fetching from `GET /api/v1/users`, and wire the "+ Invite Member" modal to `POST /api/v1/users/invite`.
- **Dependencies:** `UserController` (already implemented in backend).
- **Risk:** Medium (broken feature in UI).
- **Estimated Complexity:** Medium.
- **Suggested Order:** 4.
- **Acceptance Criteria:** Team list renders real database users, shows actual roles and statuses, and allows Owners to invite new users from the UI.

#### Item REM-005: Implement Native Anthropic Claude & Google Gemini Completions
- **Related Issue:** `AI-001`
- **Required Change:** Implement real HTTP API calls for Anthropic (`/v1/messages`) and Google Gemini (`generateContent`) inside `AiManager::executeAiCompletion` instead of falling back to the hardcoded simulation array.
- **Dependencies:** `AiManager`.
- **Risk:** Medium (users selecting non-OpenAI models receive static text).
- **Estimated Complexity:** Medium.
- **Suggested Order:** 5.
- **Acceptance Criteria:** When user selects Anthropic or Gemini with valid keys, real AI responses are returned and token usage is logged.

---

### Priority 2: Integration Gaps & Live Verifications

#### Item REM-006: Live Provider Sandbox Handshake & Verification
- **Related Issue:** `INT-001`
- **Required Change:** Obtain valid developer test credentials for Meta, Google, and TikTok. Execute real OAuth flow in staging environment and capture live provider response payload.
- **Dependencies:** External provider app approval / developer account.
- **Risk:** Medium (OAuth redirects or scopes may require provider app review).
- **Estimated Complexity:** Large.
- **Suggested Order:** 6.
- **Acceptance Criteria:** Real access tokens returned from live providers, real accounts listed, and live campaigns synced.

#### Item REM-007: Ingest AdSet / Ad Group and Creative Ad Hierarchies
- **Related Issue:** `INT-002`
- **Required Change:** Expand `fetchCampaigns` and `fetchInsights` in provider adapters to fetch AdSets and Ads. Update `AdDataSyncEngine` to populate `ad_set_id` and `ad_id` foreign keys in `performance_metrics`.
- **Dependencies:** `AdDataSyncEngine`.
- **Risk:** Low (database schema already supports `ad_sets` and `ads`).
- **Estimated Complexity:** Large.
- **Suggested Order:** 7.
- **Acceptance Criteria:** `ad_sets` and `ads` tables are populated during sync cycles and visible in campaign drilldown.

#### Item REM-008: Deploy Looker Studio Connector to Google Apps Script
- **Related Issue:** `BI-001`
- **Required Change:** Create an Apps Script project, copy `looker-studio/connector.js`, configure `appsscript.json` manifest, create a test deployment, and connect it to a live Looker Studio report.
- **Dependencies:** Google Workspace account.
- **Risk:** Low.
- **Estimated Complexity:** Small.
- **Suggested Order:** 8.
- **Acceptance Criteria:** Looker Studio dashboard displays live ad spend charts streamed directly from the platform's reporting API.

---

### Priority 3: Performance, Financial & QA Enhancements

#### Item REM-009: Multi-Currency Normalization & Exchange Rates
- **Related Issue:** `DATA-001`
- **Required Change:** Introduce an exchange rate table or third-party FX rate lookup so `AnalyticsController::overview` can normalize mixed currencies (e.g. EUR + USD) into a unified base reporting currency.
- **Dependencies:** Analytics API.
- **Risk:** Low.
- **Estimated Complexity:** Medium.
- **Suggested Order:** 9.
- **Acceptance Criteria:** Blended spend totals accurately account for currency exchange rates.

#### Item REM-010: Expand Frontend Unit Test Coverage
- **Related Issue:** `FE-002`
- **Required Change:** Add Vitest unit tests for `DashboardFeatureComponent`, `CampaignsFeatureComponent`, `AccountsFeatureComponent`, `BillingFeatureComponent`, and `AiCenterFeatureComponent`.
- **Dependencies:** None.
- **Risk:** Low.
- **Estimated Complexity:** Medium.
- **Suggested Order:** 10.
- **Acceptance Criteria:** Frontend test suite increases from 7 tests to >= 25 tests covering feature component interactions.
