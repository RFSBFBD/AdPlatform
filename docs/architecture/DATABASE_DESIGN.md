# Database Schema Design — Advertising Management Platform

## 1. Overview & Principles

The database runs on **PostgreSQL 16**.
- **Primary Keys:** UUIDv7 or auto-incrementing BigInteger with UUID as public identifiers.
- **Audit Trails:** `created_at`, `updated_at`, and soft deletes (`deleted_at`) on core entities.
- **Timezone Awareness:** All timestamps stored in `TIMESTAMP WITH TIME ZONE` (UTC).
- **Auditability:** Raw provider payloads stored in JSONB to ensure 100% data traceability.

---

## 2. Entity-Relationship Overview

```
 [users] 1 --- * [audit_logs]
    |
    * --- * [roles] (via model_has_roles)
    |
    1 --- * [invitations]

 [provider_developer_configs] (1 per provider: meta, google, tiktok, etc.)

 [connected_ad_accounts] 1 --- * [campaigns]
    |                                 |
    |                                 1 --- * [ad_sets]
    |                                            |
    |                                            1 --- * [ads]
    |                                                      |
    +------------------------------------------------------+
    |
    1 --- * [performance_metrics] (Daily aggregated data)
    1 --- * [raw_provider_payloads] (Audit / Traceability)
    1 --- * [sync_runs] (Job execution tracking)
    1 --- * [billing_invoices] (Official invoice/balance records)

 [ai_configs] (OpenAI, Anthropic, Gemini API configurations)
```

---

## 3. Core Tables Specification

### 3.1 Identity & Access Control

#### `users`
- `id` (bigint, PK)
- `uuid` (uuid, unique, indexed)
- `name` (varchar 255)
- `email` (varchar 255, unique, indexed)
- `password` (varchar 255, hashed)
- `email_verified_at` (timestamptz, nullable)
- `remember_token` (varchar 100, nullable)
- `status` (varchar 50: `active`, `suspended`, `invited`)
- `created_at`, `updated_at`, `deleted_at`

#### `roles` & `permissions`
- Standard Spatie / Laravel RBAC tables (`roles`, `permissions`, `model_has_roles`, `role_has_permissions`).
- Built-in roles: `Owner`, `Admin`, `Analyst`, `Viewer`.

#### `audit_logs`
- `id` (bigint, PK)
- `user_id` (bigint, nullable, FK -> users)
- `action` (varchar 100) — e.g. `auth.login`, `credential.updated`, `account.connected`
- `entity_type` (varchar 100, nullable)
- `entity_id` (varchar 100, nullable)
- `ip_address` (inet, nullable)
- `user_agent` (text, nullable)
- `metadata` (jsonb, nullable) — changes diff (excluding sensitive secret values)
- `created_at` (timestamptz)

---

### 3.2 Developer Credentials & Integrations

#### `provider_developer_configs`
- `id` (bigint, PK)
- `provider` (varchar 50, unique) — `meta`, `google`, `tiktok`
- `app_id` (varchar 255, nullable)
- `app_secret` (text, encrypted) — client secret, encrypted at rest
- `developer_token` (text, nullable, encrypted) — e.g. Google Ads Developer Token
- `redirect_uri` (varchar 500, nullable)
- `api_version` (varchar 50, nullable)
- `is_enabled` (boolean, default true)
- `last_tested_at` (timestamptz, nullable)
- `test_status` (varchar 50: `untested`, `passed`, `failed`)
- `test_error` (text, nullable)
- `created_at`, `updated_at`

#### `connected_ad_accounts`
- `id` (bigint, PK)
- `uuid` (uuid, unique)
- `provider` (varchar 50) — `meta`, `google`, `tiktok`
- `external_account_id` (varchar 255, indexed) — Provider's native Ad Account ID
- `account_name` (varchar 255)
- `currency` (varchar 10) — e.g. `USD`, `EUR`, `EGP`
- `timezone` (varchar 100) — e.g. `America/New_York`, `UTC`
- `access_token` (text, encrypted)
- `refresh_token` (text, nullable, encrypted)
- `token_expires_at` (timestamptz, nullable)
- `status` (varchar 50: `connected`, `expired`, `revoked`, `error`)
- `last_synced_at` (timestamptz, nullable)
- `created_at`, `updated_at`
- *Constraints:* Unique(`provider`, `external_account_id`)

---

### 3.3 Advertising Hierarchy & Performance Data

#### `raw_provider_payloads`
- `id` (bigserial, PK)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `endpoint` (varchar 255)
- `reporting_date` (date, nullable)
- `payload` (jsonb) — Original, unmutated JSON from the advertising API
- `created_at` (timestamptz)
- *Indexes:* `(connected_ad_account_id, reporting_date)`, `(created_at)`

#### `campaigns`
- `id` (bigint, PK)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `external_campaign_id` (varchar 255, indexed)
- `name` (varchar 500)
- `status` (varchar 50: `ACTIVE`, `PAUSED`, `ARCHIVED`, `DELETED`)
- `objective` (varchar 100, nullable)
- `buying_type` (varchar 50, nullable)
- `daily_budget` (numeric(18, 4), nullable)
- `lifetime_budget` (numeric(18, 4), nullable)
- `budget_currency` (varchar 10)
- `metadata` (jsonb, nullable)
- `created_at`, `updated_at`
- *Constraints:* Unique(`connected_ad_account_id`, `external_campaign_id`)

#### `ad_sets`
- `id` (bigint, PK)
- `campaign_id` (bigint, FK -> campaigns)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `external_ad_set_id` (varchar 255, indexed)
- `name` (varchar 500)
- `status` (varchar 50)
- `targeting_summary` (jsonb, nullable)
- `created_at`, `updated_at`
- *Constraints:* Unique(`connected_ad_account_id`, `external_ad_set_id`)

#### `ads`
- `id` (bigint, PK)
- `ad_set_id` (bigint, FK -> ad_sets)
- `campaign_id` (bigint, FK -> campaigns)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `external_ad_id` (varchar 255, indexed)
- `name` (varchar 500)
- `status` (varchar 50)
- `creative_type` (varchar 100, nullable)
- `creative_url` (text, nullable)
- `created_at`, `updated_at`
- *Constraints:* Unique(`connected_ad_account_id`, `external_ad_id`)

#### `performance_metrics` (Normalized Time-Series)
- `id` (bigserial, PK)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `campaign_id` (bigint, FK -> campaigns, nullable)
- `ad_set_id` (bigint, FK -> ad_sets, nullable)
- `ad_id` (bigint, FK -> ads, nullable)
- `provider` (varchar 50)
- `date` (date, indexed)
- `currency` (varchar 10)
- `spend` (numeric(18, 4), default 0)
- `impressions` (bigint, default 0)
- `clicks` (bigint, default 0)
- `conversions` (numeric(18, 4), default 0)
- `conversion_value` (numeric(18, 4), default 0)
- `reach` (bigint, nullable)
- `attribution_model` (varchar 100, nullable)
- `raw_metrics` (jsonb, nullable) — extra provider-specific KPIs (e.g. video views, leads)
- `created_at`, `updated_at`
- *Unique Constraint / Idempotency Key:* `(connected_ad_account_id, ad_id, date, provider)`
- *Composite Indexes:* `(connected_ad_account_id, date)`, `(campaign_id, date)`, `(provider, date)`

---

### 3.4 Data Engine, Billing & AI Configurations

#### `sync_runs`
- `id` (bigserial, PK)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `sync_type` (varchar 50: `initial`, `incremental`, `backfill`, `manual`)
- `status` (varchar 50: `pending`, `running`, `completed`, `failed`)
- `date_from` (date, nullable)
- `date_to` (date, nullable)
- `records_ingested` (integer, default 0)
- `error_message` (text, nullable)
- `started_at` (timestamptz, nullable)
- `completed_at` (timestamptz, nullable)
- `created_at`, `updated_at`

#### `billing_invoices`
- `id` (bigint, PK)
- `connected_ad_account_id` (bigint, FK -> connected_ad_accounts)
- `provider` (varchar 50)
- `external_invoice_id` (varchar 255)
- `invoice_date` (date)
- `due_date` (date, nullable)
- `amount` (numeric(18, 4))
- `currency` (varchar 10)
- `payment_status` (varchar 50: `paid`, `pending`, `failed`, `overdue`)
- `metadata` (jsonb, nullable)
- `created_at`, `updated_at`

#### `ai_configs`
- `id` (bigint, PK)
- `provider` (varchar 50, unique) — `openai`, `anthropic`, `gemini`
- `api_key` (text, encrypted)
- `organization_id` (varchar 255, nullable)
- `default_model` (varchar 100)
- `is_enabled` (boolean, default false)
- `usage_metadata` (jsonb, nullable)
- `created_at`, `updated_at`
