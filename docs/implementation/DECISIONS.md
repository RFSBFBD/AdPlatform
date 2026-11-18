# Architectural & Technical Decisions Log (ADR)

This document records the architectural, technical, and operational decisions made for the Advertising Management Platform, along with their context, rationale, and consequences.

---

## ADR-001: Technology Stack Selection

### Context
The platform requires high performance, strict multi-platform API integration capabilities, secure secret handling, reliable background queue processing, and a scalable, modern single-page frontend.

### Decision
- **Frontend:** Angular (v18+) with TypeScript, utilizing Standalone Components, Angular Signals, and modern routing.
- **Backend:** Laravel 11 (PHP 8.3+) utilizing RESTful API architecture, Laravel Sanctum for first-party authentication, and Laravel Queues/Scheduler.
- **Database:** PostgreSQL 16 as the primary relational database.
- **Cache & Queue Broker:** Redis for caching, rate limiting, distributed locking, and queue management.
- **Local Containerization:** Docker Compose managing Nginx, PHP-FPM, PostgreSQL, Redis, Queue Worker, and Scheduler.

### Rationale
- Laravel provides rock-solid security defaults, built-in queue workers, scheduled tasks, and robust encryption out of the box.
- PostgreSQL offers superior handling of JSONB (for raw provider payloads), complex analytical aggregations, and robust concurrency control.
- Angular enforces strong type-safety and architectural discipline for enterprise-grade SaaS dashboards.
- Explicit exclusions (Supabase, Firebase, Clerk, React components) avoid vendor lock-in and keep authentication and business logic strictly controlled within first-party infrastructure.

---

## ADR-002: Dual-Layer Data Storage Strategy (Raw + Normalized)

### Context
Advertising platforms (Meta, Google, TikTok) have disparate data models, naming conventions, currency rules, and attribution windows. Future audits may require tracing a reported metric back to the exact API response.

### Decision
Implement a two-tier data persistence strategy:
1. **Raw Storage Layer (`raw_provider_payloads`):** Stores unmutated JSON payloads tagged with `provider`, `account_id`, `endpoint`, and `ingestion_timestamp`.
2. **Normalized Reporting Layer (`campaigns`, `ad_sets`, `ads`, `performance_metrics`):** Structured relational tables representing common cross-platform concepts with standard fields (e.g. `spend`, `impressions`, `clicks`, `conversions`, `currency`, `timezone`).

### Consequences
- Requires slightly higher storage space, but guarantees complete auditability, debuggability, and the ability to re-parse historical data if schema mappings evolve.

---

## ADR-003: Encryption at Rest for API Credentials and OAuth Tokens

### Context
The platform stores high-value secrets: Provider Client Secrets, OAuth Access Tokens, Refresh Tokens, and AI API Keys (OpenAI, Anthropic, Gemini).

### Decision
- All sensitive credentials stored in PostgreSQL must be encrypted using Laravel's native encryption facilities (`Crypt::encryptString` / `encrypted` Eloquent attribute casts).
- Decrypted values must **never** be transmitted to the frontend.
- API endpoints returning credential configurations must return masked values (e.g., `sk-••••••••1234`).
- Master encryption keys (`APP_KEY`) reside in environment variables and are excluded from version control.

---

## ADR-004: Deployment Topology & Separation of Concerns

### Context
The application needs preview capabilities for frontend testing while maintaining an independent, persistent environment for the API, queues, and database.

### Decision
- **Frontend Preview:** Deployed to Vercel (static Angular build), pointing to the staging or production Laravel API via environment-specific configuration (`environment.prod.ts`).
- **Backend API & Data Engine:** Deployed via Docker Compose to a dedicated VPS or container runtime running PHP-FPM, Nginx, PostgreSQL, Redis, and supervisor-managed queue workers and schedulers.
- **No serverless backend on Vercel:** Vercel is strictly for the Angular client.

---

## ADR-005: Idempotency and Distributed Concurrency Locks in Data Synchronization

### Context
Multiple background sync jobs or manual sync requests could run concurrently for the same advertising account, risking duplicate metrics or API rate-limit exhaustion.

### Decision
- Utilize Redis atomic locks (`Cache::lock('sync:account:{id}', $seconds)`) to guarantee single-flight execution per account and date range.
- Enforce database unique constraints on `performance_metrics` (`ad_account_id`, `ad_id`, `date`, `provider`) with upsert logic (`ON CONFLICT DO UPDATE`) to ensure absolute idempotency.
