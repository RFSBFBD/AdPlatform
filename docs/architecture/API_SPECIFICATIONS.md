# API Specifications & Contracts — Advertising Management Platform

## 1. Conventions & Standards

- **Base URL:** `/api/v1`
- **Format:** `application/json`
- **Authentication:** `Bearer <token>` (Laravel Sanctum) or SameSite HTTP-Only Cookies for SPA sessions.
- **Timestamp Format:** ISO 8601 (`YYYY-MM-DDTHH:mm:ssZ`) in UTC.
- **Date Format:** `YYYY-MM-DD`
- **Currencies & Decimals:** Monetary amounts are returned as strings or high-precision floats (e.g. `"1250.5000"`) accompanied by the 3-letter currency code (e.g. `"USD"`).

---

## 2. Standard Envelope Structure

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 150
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  }
}
```

---

## 3. Core API Endpoints

### 3.1 Authentication & Profile
- `POST /api/v1/auth/login` — Authenticate and receive Sanctum token or session cookie.
- `POST /api/v1/auth/logout` — Revoke token / invalidate session.
- `GET /api/v1/auth/me` — Return current authenticated user profile, assigned roles, and permissions.
- `POST /api/v1/auth/forgot-password` — Send password reset link.
- `POST /api/v1/auth/reset-password` — Reset password using token.

### 3.2 User & Team Management (Admin / Owner)
- `GET /api/v1/users` — List users with pagination and role filters.
- `POST /api/v1/users/invite` — Send invite email to new team member.
- `PUT /api/v1/users/{id}/role` — Update user's role (`Owner`, `Admin`, `Analyst`, `Viewer`).
- `DELETE /api/v1/users/{id}` — Deactivate or soft-delete user.

### 3.3 Developer API Configuration (Owner / Admin)
- `GET /api/v1/admin/developer-configs`
  - Returns list of providers (`meta`, `google`, `tiktok`) with `app_id`, masked `app_secret` (`••••••••1234`), `redirect_uri`, `is_enabled`, and `last_tested_at`.
- `PUT /api/v1/admin/developer-configs/{provider}`
  - Updates client credentials (encrypted at rest).
- `POST /api/v1/admin/developer-configs/{provider}/test`
  - Performs connection and scope verification with the provider.

### 3.4 Connected Ad Accounts & Integrations
- `GET /api/v1/integrations/oauth/{provider}/redirect`
  - Returns official provider OAuth authorization URL with CSRF state token.
- `GET /api/v1/integrations/oauth/{provider}/callback`
  - Exchanges code for access/refresh token, discovers accessible accounts.
- `GET /api/v1/integrations/{provider}/discovered-accounts`
  - Lists accounts available for import.
- `POST /api/v1/integrations/{provider}/connect`
  - Persists selected accounts and schedules initial sync job.
- `GET /api/v1/ad-accounts`
  - Returns list of connected accounts with connection health status (`active`, `expired`, `error`).
- `DELETE /api/v1/ad-accounts/{id}`
  - Disconnects account and halts sync jobs.
- `POST /api/v1/ad-accounts/{id}/sync`
  - Triggers on-demand manual synchronization.

### 3.5 Advertising Analytics & Campaigns
- `GET /api/v1/analytics/overview`
  - **Query Params:** `date_from`, `date_to`, `providers[]`, `account_ids[]`
  - **Returns:** Aggregated metrics (`total_spend`, `total_impressions`, `total_clicks`, `total_conversions`, `roas`, `cpc`, `cpm`, `ctr`), timeline trends, and platform share.
- `GET /api/v1/analytics/campaigns`
  - **Query Params:** `date_from`, `date_to`, `account_ids[]`, `status`, `search`, `sort_by`, `page`, `per_page`
  - **Returns:** Paginated list of campaigns with associated performance metrics.
- `GET /api/v1/analytics/campaigns/{id}`
  - Detailed breakdown including underlying ad sets, ads, and daily performance curve.

### 3.6 Billing & Payments
- `GET /api/v1/billing/summary` — Aggregate billed amount, outstanding balances, and last payment date across connected accounts.
- `GET /api/v1/billing/invoices` — List provider invoices where exposed by official APIs.

### 3.7 AI Integrations Center
- `GET /api/v1/admin/ai-configs` — Returns configuration status for OpenAI, Anthropic, Gemini.
- `PUT /api/v1/admin/ai-configs/{provider}` — Save encrypted API key, default model, and rate settings.
- `POST /api/v1/admin/ai-configs/{provider}/test-connection` — Test API handshake.
- `POST /api/v1/ai/generate-insights` — Generate executive summary or campaign anomaly analysis.

### 3.8 Looker Studio Connector
- `GET /api/v1/reporting/looker-studio/schema` — Return dimensions and metrics definitions.
- `POST /api/v1/reporting/looker-studio/data` — Authenticated endpoint returning normalized tabular reporting data for Looker Studio ingestion.
