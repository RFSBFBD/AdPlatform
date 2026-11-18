# System Architecture — Advertising Management Platform

## 1. High-Level System Overview

The Advertising Management Platform is a decoupled, enterprise SaaS platform consisting of:
1. **Frontend Client:** An Angular single-page application (SPA) offering real-time metric analysis, campaign monitoring, account linking, and configuration management.
2. **Backend API & Data Engine:** A Laravel RESTful API responsible for authentication, authorization, encrypted credential storage, official OAuth handling, background synchronization, and analytical aggregations.
3. **Data Tier:** PostgreSQL 16 (relational & JSONB data) and Redis 7 (caching, distributed locks, rate limiting, and queue queuing).
4. **External Integrations:** Direct official connections to Meta Marketing API, Google Ads API, TikTok Marketing API, AI Providers (OpenAI, Anthropic, Gemini), and Google Looker Studio.

```
                      +-----------------------------------+
                      |         Angular 18+ SPA           |
                      |   (Desktop / Tablet / Mobile)     |
                      +-----------------+-----------------+
                                        |  HTTPS / REST
                                        v
                      +-----------------+-----------------+
                      |         Nginx Web Server          |
                      |      (Reverse Proxy / SSL)        |
                      +-----------------+-----------------+
                                        |  FastCGI
                                        v
                      +-----------------+-----------------+
                      |       Laravel 11 (PHP-FPM)        |
                      |  - Sanctum Authentication         |
                      |  - RBAC Policy Gateways           |
                      |  - Credential Encryption          |
                      |  - Analytics & Metric Engines     |
                      +-------+-------------------+-------+
                              |                   |
            +-----------------+                   +-----------------+
            |                                                       |
            v                                                       v
+-----------+-----------+                               +-----------+-----------+
|     PostgreSQL 16     |                               |        Redis 7        |
| - Users & Audit Logs  |                               | - Queues & Jobs       |
| - Raw Payloads (JSONB)|                               | - Cache & Locks       |
| - Normalized Metrics  |                               | - Rate Limiting       |
+-----------------------+                               +-----------+-----------+
                                                                    |
                                                                    v
                                                        +-----------+-----------+
                                                        | Laravel Queue Worker  |
                                                        | & Scheduled Crons     |
                                                        +-----------+-----------+
                                                                    |
                                        +---------------------------+---------------------------+
                                        |                           |                           |
                                        v                           v                           v
                             +----------+----------+     +----------+----------+     +----------+----------+
                             |    Meta Ads API     |     |   Google Ads API    |     |   TikTok Ads API    |
                             |  (Marketing API)    |     |     (REST/gRPC)     |     |   (Business API)    |
                             +---------------------+     +---------------------+     +---------------------+
```

---

## 2. Frontend Architecture (Angular)

### Architecture Principles
- **Standalone Components:** Modern Angular architecture without legacy `NgModule` clutter.
- **Signals & Reactive State:** Utilize Angular Signals for reactive local and shared state.
- **Strict Typing:** All models, API requests, and responses are strictly typed via TypeScript interfaces.
- **Feature Module Boundaries:**
  - `core/`: Singleton services, HTTP interceptors (Auth, Error, Loading), guards.
  - `shared/`: Reusable UI components (buttons, tables, modals, charts), pipes, directives.
  - `features/`:
    - `auth/`: Login, password reset, profile.
    - `dashboard/`: Overview analytics, widgets, date filter bar.
    - `campaigns/`: Campaign lists, ad set drilldowns, performance breakdowns.
    - `accounts/`: Connected ad accounts, OAuth initiation, account selection.
    - `developer-settings/`: Admin configuration of Meta, Google, TikTok developer app keys.
    - `billing/`: Invoices, transactions, balance summaries.
    - `ai-center/`: AI provider configurations and AI assistants.
    - `team/`: User management, invitations, role assignments.

### Theme & Styling Architecture
- Fully responsive design using modern CSS/Tailwind variables.
- Dual theme engine (Light / Dark mode) with CSS custom properties (`--bg-primary`, `--text-primary`, `--border-color`, etc.).
- Respects OS preference (`prefers-color-scheme`) on initial load and persists user preference in `localStorage`.

---

## 3. Backend Architecture (Laravel)

### Layered Structure
- **Controllers:** Thin controllers handling HTTP requests, delegating to Services/Actions, returning standardized API Resources.
- **Form Requests:** Strict validation of incoming payloads with sanitized input.
- **Policies / Middleware:** Granular RBAC authorization on every protected route.
- **Domain Services:** Encapsulated business logic:
  - `AuthService`: Token creation, user onboarding.
  - `CredentialManagerService`: Encrypted storage and retrieval of provider credentials.
  - `OAuthManager`: Generic driver-based OAuth orchestrator for Meta, Google, and TikTok.
  - `AdvertisingSyncService`: Multi-provider data ingestion, normalization, and reconciliation.
  - `AnalyticsEngine`: Aggregation queries, metric calculations (ROAS, CPC, CTR), attribution filters.
  - `AiIntegrationService`: Multi-vendor AI adapter for OpenAI, Claude, and Gemini.
- **Data Adapters (`App\Services\Advertising\Adapters`):**
  - `MetaAdsAdapter`
  - `GoogleAdsAdapter`
  - `TikTokAdsAdapter`
- **Jobs & Queue Workers:** Asynchronous processing of heavy API syncs, historical backfills, and report generation.

---

## 4. Security & Compliance Architecture

1. **Authentication:** Laravel Sanctum issuing secure bearer tokens for mobile/CLI/external and HTTP-only SameSite cookies for first-party SPA communication.
2. **Authorization:** Role-Based Access Control (RBAC):
   - `Owner`: Complete tenant control, billing, user management, credential configuration.
   - `Admin`: User management, integration management, campaign actions.
   - `Analyst`: Read & export analytics, view connected accounts, execute AI reports.
   - `Viewer`: Read-only access to authorized dashboards and reports.
3. **Data Protection:**
   - Database encryption at rest for all tokens, client secrets, and API keys.
   - Zero raw secrets returned in frontend API responses.
   - Audit logging for all authentication events, role changes, and credential updates.
   - Strict CORS and CSRF protection configured for production domains.
