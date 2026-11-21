# SECURITY AUDIT & TENANCY VERIFICATION REPORT

**Project:** Advertising Management Platform  
**Auditors:** Independent Principal Security Engineer & Senior QA Auditor  
**Audit Date:** 2026-11-19  
**Classification:** Security Vulnerability Assessment  

---

## 1. Tenancy Scoping Verification by Endpoint

We performed independent static code analysis and cross-user behavioral analysis on every endpoint that reads, modifies, exports, or synchronizes advertising data.

| Endpoint | Method | Tenancy Mechanism | Verified Behavior | Status |
|---|---|---|---|---|
| `/api/v1/ad-accounts` | GET | `where('user_id', $user->id)` | Returns only accounts owned by requesting user. Tested with two distinct users in `TenancyIsolationTest`. | **VERIFIED** |
| `/api/v1/ad-accounts/{id}` | DELETE | RBAC + ownership check | Rejects unauthorized users and non-owners with HTTP 403 Forbidden. | **VERIFIED** |
| `/api/v1/ad-accounts/{id}/sync` | POST | `$account->user_id === $user->id` | Rejects cross-user sync triggers with HTTP 403 Forbidden. | **VERIFIED** |
| `/api/v1/ad-accounts/{id}/sync-status` | GET | `$account->user_id === $user->id` | Rejects cross-user sync status read with HTTP 403 Forbidden. | **VERIFIED** |
| `/api/v1/ad-accounts/{id}/sync-history` | GET | `$account->user_id === $user->id` | Rejects cross-user sync history read with HTTP 403 Forbidden. | **VERIFIED** |
| `/api/v1/analytics/overview` | GET | `$query->whereIn('connected_ad_account_id', $userAccountIds)` | Aggregates spend and metrics only from accounts owned by requesting user. | **VERIFIED** |
| `/api/v1/analytics/campaigns` | GET | `$query->whereIn('connected_ad_account_id', $userAccountIds)` | Returns only campaigns belonging to requesting user's accounts. | **VERIFIED** |
| `/api/v1/analytics/campaigns/{id}` | GET | Campaign account user check | Direct access to another user's campaign ID returns HTTP 403 Forbidden. | **VERIFIED** |
| `/api/v1/billing/summary` | GET | Accounts scoped to `user_id` | Balances and payment profiles isolated per user. | **VERIFIED** |
| `/api/v1/billing/invoices` | GET | Invoices scoped to `userAccountIds` | Invoices list isolated per user. | **VERIFIED** |
| `/api/v1/reporting/looker-studio` | GET | **NONE** | **Omits user scoping**. Exposes all platform metrics across all accounts to any authenticated token. | **FAILED** |
| `/api/v1/users` | GET | `permission:manage-users` | Restricted to Owner/Admin. Viewer/Analyst receive HTTP 403. | **VERIFIED** |
| `/api/v1/users/invite` | POST | `permission:manage-users` | Restricted to Owner/Admin. Viewer/Analyst receive HTTP 403. | **VERIFIED** |
| `/api/v1/admin/developer-configs` | GET | `permission:manage-developers` | Restricted to Owner/Admin. Viewer/Analyst receive HTTP 403. | **VERIFIED** |

---

## 2. Security Vulnerability Details

### Vulnerability SEC-POST-01: Cross-Tenant Data Leak in Looker Studio Reporting Endpoint
- **Severity:** HIGH (CVSS 7.5 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N`)
- **Status:** **FAILED**
- **Affected File:** `backend/app/Http/Controllers/Api/V1/LookerStudioController.php`
- **Affected Method:** `LookerStudioController::handle` (lines 45–68)
- **Vulnerability Description:**
  The Looker Studio BI connector endpoint is protected by Sanctum token authentication (`Route::middleware('auth:sanctum')`), but the controller query does not scope performance metrics to the authenticated user's ad accounts.
- **Code Inspection:**
  ```php
  // backend/app/Http/Controllers/Api/V1/LookerStudioController.php:45-48
  $query = PerformanceMetric::query()
      ->join('connected_ad_accounts', 'performance_metrics.connected_ad_account_id', '=', 'connected_ad_accounts.id')
      ->leftJoin('campaigns', 'performance_metrics.campaign_id', '=', 'campaigns.id')
      ->whereBetween('performance_metrics.date', [$startDate, $endDate])
  ```
  Notice there is no `where('connected_ad_accounts.user_id', $request->user()->id)`.
- **Reproduction Scenario:**
  1. User A (Tenant A) connects an ad account and syncs \$50,000 in advertising spend across 20 campaigns.
  2. User B (Tenant B, a competitor or unrelated organization) registers an account on the platform and logs in to obtain a personal Sanctum token.
  3. User B issues an HTTP GET request to `/api/v1/reporting/looker-studio?start_date=2026-01-01&end_date=2026-12-31` with `Authorization: Bearer <User_B_Token>`.
  4. Response: User B receives reporting rows containing Tenant A's account names, campaign names, spend, clicks, conversions, and revenue metrics.
- **Remediation Recommendation:**
  Add user scoping to the query:
  ```php
  $userAccountIds = ConnectedAdAccount::where('user_id', $request->user()->id)->pluck('id');
  $query->whereIn('performance_metrics.connected_ad_account_id', $userAccountIds);
  ```

---

### Vulnerability SEC-POST-02: Account Hijacking via Global External Account ID Uniqueness
- **Severity:** MEDIUM (CVSS 6.5 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:H/A:L`)
- **Status:** **VERIFIED_WITH_LIMITATIONS**
- **Affected File:** `backend/app/Http/Controllers/Api/V1/IntegrationController.php` (lines 141–156)
- **Affected Table:** `connected_ad_accounts` (`unique(['provider', 'external_account_id'])`)
- **Vulnerability Description:**
  The `connected_ad_accounts` table enforces a global unique constraint on `(provider, external_account_id)`. When an account is connected, `IntegrationController::connectAccounts` uses `ConnectedAdAccount::updateOrCreate`:
  ```php
  $account = ConnectedAdAccount::updateOrCreate(
      [
          'provider' => $provider,
          'external_account_id' => $accId,
      ],
      [
          'user_id' => $request->user()->id,
          'account_name' => $accountMeta['account_name'],
          ...
      ]
  );
  ```
- **Reproduction Scenario:**
  1. User A connects Google Ads account `999-888-7776`.
  2. User B obtains or has shared read access to Google Ads account `999-888-7776` and completes the OAuth flow.
  3. When User B clicks "Connect", `updateOrCreate` matches the existing record and updates `user_id` to User B's ID.
  4. User A instantly loses visibility of the ad account in their dashboard, while User B gains full access to all historical data previously synced under that account.
- **Remediation Recommendation:**
  Before updating, check if the account already exists and is associated with another user:
  ```php
  $existing = ConnectedAdAccount::where('provider', $provider)
      ->where('external_account_id', $accId)
      ->first();

  if ($existing && $existing->user_id !== $request->user()->id) {
      throw new RuntimeException("This advertising account is already connected by another user.");
  }
  ```

---

### Vulnerability SEC-POST-03: Null Ownership Bypass in Individual Check Methods
- **Severity:** LOW (CVSS 4.3 - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:L/A:N`)
- **Status:** **VERIFIED_WITH_LIMITATIONS**
- **Affected Files:**
  - `IntegrationController::disconnectAccount`
  - `AnalyticsController::campaignDetails`
  - `SyncController::triggerSync`, `getStatus`, `getHistory`
- **Vulnerability Description:**
  The authorization checks use the conditional guard:
  ```php
  if ($account->user_id !== null && $account->user_id !== $request->user()->id && !$request->user()->hasAnyRole(['Owner', 'Admin'])) {
      return response()->json(['error' => 'FORBIDDEN'], 403);
  }
  ```
  If `account->user_id` is `null` (e.g. an account created prior to migration or without a user assigned), the condition evaluates to `false`. Any user can then view campaign details, trigger syncs, or delete the account.
- **Remediation Recommendation:**
  Change condition to reject null ownership unless the user is an Owner or Admin:
  ```php
  if ($account->user_id !== $request->user()->id && !$request->user()->hasAnyRole(['Owner', 'Admin'])) {
      return response()->json(['error' => 'FORBIDDEN'], 403);
  }
  ```

---

## 3. Role-Based Access Control (RBAC) Matrix

We verified role enforcement against all four system roles defined in `RoleAndPermissionSeeder.php`:

| Operation | Required Permission | Owner | Admin | Analyst | Viewer | Backend Enforcement Evidence |
|---|---|---|---|---|---|---|
| **View Analytics** | `view-analytics` | ALLOW | ALLOW | ALLOW | ALLOW | Permitted on all roles |
| **Trigger Sync** | `sync-campaigns` | ALLOW | ALLOW | ALLOW | DENIED | Route middleware / Controller check |
| **Disconnect Account** | `manage-accounts` | ALLOW | ALLOW | DENIED (403) | DENIED (403) | Verified via `test_viewer_role_cannot_disconnect_an_ad_account_sec_002` |
| **Invite Team Member** | `manage-users` | ALLOW | ALLOW | DENIED (403) | DENIED (403) | Verified via `test_viewer_cannot_access_user_management` |
| **Update User Role** | `manage-users` | ALLOW | ALLOW | DENIED (403) | DENIED (403) | Guarded by `permission:manage-users` |
| **Delete User** | `manage-users` | ALLOW | ALLOW | DENIED (403) | DENIED (403) | Guarded by `permission:manage-users` |
| **Manage Developer APIs** | `manage-developers` | ALLOW | ALLOW | DENIED (403) | DENIED (403) | Verified via `DeveloperConfigTest::test_analyst_cannot_access_developer_configs` |
| **Manage Billing** | `manage-billing` | ALLOW | DENIED | DENIED | DENIED | Only `Owner` has `manage-billing` permission |
