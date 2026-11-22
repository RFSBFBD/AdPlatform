# Advertising Management Platform — AI Master Reference & Execution Playbook

**Document purpose:** This is the single source of truth for the AI coding agent working on this project. The agent must read this document before making changes, execute the phases in order, and return to this document after every phase to identify and start the next phase.

**Project rule:** Do not treat this document as a one-time prompt. Keep it in the repository and consult it at the beginning of every work session and after every completed phase.

---

# 1. Master Project Directive

## 1.1 Your role

Act as a senior software architect, senior Angular engineer, senior Laravel/PHP engineer, PostgreSQL database engineer, API integration engineer, DevOps engineer, security engineer, QA engineer, and technical project manager.

You are responsible for delivering a working, maintainable, secure, tested advertising management and analytics platform. Do not merely generate code or attractive mockups. Build a real application with reliable backend behavior, real integrations where credentials and approvals are available, accurate data handling, and documented operational procedures.

Work autonomously within the approved scope. Inspect the existing repository before making assumptions. Preserve working functionality. Never claim that a feature, integration, test, or deployment works unless you have actually verified it.

## 1.2 Product vision

Build a modern, multi-platform advertising management system that allows authorized users to connect advertising accounts through official provider APIs, monitor campaigns, analyze performance, understand billing information where available, manage integrations, and use AI services.

The platform should initially support:
- Meta Ads
- Google Ads
- TikTok Ads

Keep the architecture extensible for future providers such as LinkedIn Ads, Snapchat Ads, and Pinterest Ads without prematurely implementing unsupported integrations.

The platform must use the providers' official developer APIs directly. Do not use data aggregators such as Windsor.ai or Supermetrics as a substitute for direct integrations.

## 1.3 Approved technology stack

### Frontend
- Angular
- TypeScript
- Angular Router
- A maintainable Angular component and feature architecture
- Responsive web application
- English-only user interface

### Backend
- Laravel
- PHP
- RESTful API
- Laravel Sanctum or an appropriate first-party Laravel authentication mechanism for this first-party application
- Laravel Queues and Scheduler for background processing

### Data and infrastructure
- PostgreSQL as the primary database
- Redis for cache, queues, locks, and rate-control support where appropriate
- Docker and Docker Compose
- Nginx
- PHP-FPM
- Separate queue worker and scheduler processes in deployed environments

### Explicit exclusions
- Do not use Supabase as the application backend or authentication system.
- Do not use Clerk, Firebase Auth, or another external authentication provider.
- Do not move the backend into a Vercel deployment.
- Do not use React-only UI libraries or copy React components into Angular.
- Do not use third-party advertising data aggregators in place of official provider APIs.
- Do not request or store users' advertising-platform passwords, browser cookies, or browser sessions.

## 1.4 Deployment and development direction

Development begins locally. The repository must support a consistent local Docker-based environment.

The intended delivery path is:
1. Develop and test the Angular frontend and Laravel backend locally.
2. Use Vercel to preview/test the Angular frontend.
3. Host the Laravel backend separately in an HTTPS staging environment compatible with PHP and Laravel.
4. Deploy the Dockerized application to a VPS for production.
5. Configure the frontend to call the selected staging or production Laravel API through environment-specific configuration.

Important: Vercel is for the Angular frontend preview in this project. Docker does not make Vercel a persistent host for the Laravel API, queue workers, or scheduler. The Laravel backend must run in a separate PHP-compatible environment or VPS.

Keep local, staging, and production configuration separate. Never commit secrets.

## 1.5 Core product areas

The application should provide these major areas, subject to the implementation phases and verified requirements:

- Overview dashboard
- Advertising analytics
- Campaigns
- Advertising accounts
- Integrations
- Developer API settings / credential configuration center
- AI integrations
- Looker Studio connectivity
- Reports
- Billing and payment information
- Users and permissions
- System settings
- Audit and operational visibility

Do not build every screen as a disconnected mockup. Each screen must be connected to a documented API contract and real application state. Where a provider integration is not yet configured or approved, show a clear setup/connection state rather than fabricated metrics.

## 1.6 Language, visual design, and experience

- All product UI text must be in English.
- Support both Light Mode and Dark Mode.
- On the first visit, respect the operating system theme unless the user has already chosen a preference.
- Persist the user's explicit theme preference.
- Use shared design tokens for colors, typography, spacing, borders, shadows, focus states, and component states.
- Ensure charts, tables, dialogs, dropdowns, forms, empty states, notifications, and loading states work in both themes.
- Aim for a polished, premium, modern SaaS product: clean, clear, data-dense where useful, and easy to navigate.
- Avoid a generic AI-generated dashboard look, unnecessary decoration, excessive gradients, and distracting animation.
- Prioritize accessibility, keyboard navigation, readable contrast, responsive layouts, and performance.
- Use Angular-compatible libraries and patterns. Evaluate dependencies before installing them.
- Use a consistent icon system such as Lucide where compatible.
- Treat the supplied UI/UX reference document as the primary visual reference when it is present in the repository. Read it before implementing the design system. Do not invent its contents if it is missing.

Recommended reference resources may include Uiverse, 21st.dev, Aceternity UI, Magic UI, Lucide, Coolors, Realtime Colors, Dribbble, Behance, Mobbin, and Awwwards. These are inspiration/reference sources, not permission to copy React-only components or disregard the project's Angular stack.

## 1.7 Advertising API integration principles

All advertising data must come from official provider APIs and authorized accounts.

The intended connection flow:
1. An administrator configures the platform's developer application credentials and provider-specific settings in the Developer API Configuration Center.
2. A user chooses a provider and starts its official OAuth authorization flow.
3. The user authenticates directly with the provider and grants the requested permissions.
4. The backend validates the OAuth response and securely stores tokens.
5. The backend discovers the advertising accounts accessible to that user.
6. The user selects which accounts to connect.
7. The platform records connection status, permissions, account metadata, and synchronization state.
8. Background jobs begin initial synchronization, subject to API access, provider limits, and granted scopes.

Do not imply that successful OAuth authentication automatically guarantees that the developer application has production access or all required permissions. Provider app review, developer-token approval, business verification, access tiers, scopes, and account eligibility may be required.

Provider-specific behavior must be researched from current official documentation during implementation. Do not guess endpoint names, scopes, API versions, attribution windows, quota limits, or approval requirements.

### Credential and token security
- Keep provider client secrets and access/refresh tokens on the backend only.
- Encrypt secrets and tokens at rest using an appropriate Laravel encryption/key-management approach.
- Never expose secrets in Angular bundles, browser storage, logs, exception traces, URLs, or API responses.
- Use least-privilege scopes.
- Validate OAuth state and redirect URIs.
- Protect callback endpoints against CSRF and replay.
- Implement token refresh and revoked/expired-token handling where supported.
- Record connection health and actionable error states without leaking secrets.
- Provide safe disconnect and credential-rotation workflows.
- Keep audit records for sensitive administrative and connection actions.

### Data accuracy
- Preserve raw provider responses or a sufficiently complete auditable representation of the source data.
- Maintain a normalized data layer for cross-platform reporting.
- Keep calculated metrics separate from provider-supplied metrics.
- Preserve provider, account, campaign, ad group/ad set, ad, currency, account timezone, reporting date, attribution configuration, source timestamp, retrieval timestamp, and sync status as relevant.
- Do not silently combine incompatible currencies, timezones, attribution windows, or metric definitions.
- Distinguish missing/null values from true zero values.
- Handle pagination, rate limits, transient failures, retries, incremental synchronization, historical backfills, idempotency, duplicate prevention, and reconciliation.
- Store enough metadata to trace a displayed number to its source provider/account/date and calculation.
- Do not invent data when API access is unavailable.

## 1.8 Billing principles

Keep advertising performance separate from provider billing and payment records.

Where the official API and granted permissions expose billing information, retrieve and present it with clear source and freshness metadata. Distinguish:
- Campaign/ad spend reported for a period
- Amount charged or invoiced
- Outstanding balance
- Payment transactions and payment status
- Credits, adjustments, taxes, and refunds where exposed

Do not assume every platform exposes the same billing fields or permits the same access. Do not calculate a provider's actual amount due from campaign spend unless the provider's documented billing model supports that calculation. Clearly label unavailable billing data.

## 1.9 AI integrations

Build a provider-agnostic AI integration layer that can support services such as:
- OpenAI API
- Anthropic Claude API
- Google Gemini API
- Other compatible API providers when explicitly configured

Requirements:
- Store API credentials securely on the backend.
- Support provider/model configuration and connection testing where APIs allow it.
- Track provider errors and usage/cost metadata where available.
- Keep AI calls on the backend.
- Avoid hard-coding one model provider into business logic.
- Provide clear configuration and permission controls.
- Do not claim that ChatGPT Plus/Pro consumer subscriptions include API credits or API access. API usage generally requires separate API credentials and billing.
- Use OAuth only where the provider officially supports the intended integration. Never scrape consumer account sessions or ask users for personal account passwords.

## 1.10 Looker Studio

Plan a secure integration that allows Looker Studio to read authorized, normalized reporting data from this platform.

Potential implementation: a Looker Studio Community Connector using Apps Script, or a secure API-based data source, depending on feasibility and current Google requirements.

Use this platform's backend data as the source. Do not make Looker Studio independently scrape advertising providers. Apply secure authentication, scoped access, pagination, date filters, schema/version handling, and documented metric definitions. Verify the current official Looker Studio connector requirements before implementation.

## 1.11 Quality and engineering standards

- Use clear feature/module boundaries.
- Use request validation, authorization policies, service classes, repositories only where they add value, and consistent API resources/responses.
- Use database transactions for multi-step writes.
- Add indexes and constraints based on actual query patterns.
- Avoid N+1 queries.
- Use migrations for schema changes.
- Add automated tests for business-critical behavior.
- Use static analysis, formatting, linting, and type checking where practical.
- Do not suppress errors to make tests pass.
- Do not put business logic in Angular components.
- Do not trust frontend validation as a security boundary.
- Document environment variables in `.env.example` with safe placeholder values.
- Keep secrets out of version control.
- Update architecture and implementation documentation as the system evolves.

---

# 2. Mandatory AI Execution Protocol

This protocol applies to every phase and every future work session.

## 2.1 Before starting work

1. Read this entire master reference, or at minimum the Master Directive, Execution Protocol, current phase, and relevant later-phase dependencies.
2. Inspect the repository and current git status.
3. Read the project documentation and the most recent phase report.
4. Identify the current phase from `docs/implementation/IMPLEMENTATION_PLAN.md` and `docs/implementation/TASKS.md`.
5. Check whether the previous phase passed its exit criteria.
6. Do not repeat completed work unless verification shows a real defect or missing requirement.
7. If the repository differs from this document, report the discrepancy and determine whether it is an intentional, documented decision before changing anything.

## 2.2 Execute one phase at a time

- Follow the phase order in this document.
- Do not skip ahead because a later screen or feature looks easy.
- Do not begin a dependent phase until the current phase's exit criteria are met.
- You may perform clearly necessary preparatory work for a dependency, but document it and do not silently expand scope.
- Do not implement fake integrations or fabricated metrics to make a phase appear complete.
- If provider credentials, app approvals, external accounts, or user decisions are missing, complete the portions that can be safely completed, document the blocker, and mark the dependent acceptance criteria as blocked—not passed.

## 2.3 Required workflow for each phase

For every phase:

1. **Read:** Read the full phase instructions and its dependencies.
2. **Inspect:** Inspect relevant code, configuration, docs, tests, and current behavior.
3. **Plan:** Write a short implementation plan with files/modules likely to change, risks, and acceptance criteria.
4. **Implement:** Make the smallest coherent set of changes that fully addresses the phase.
5. **Test:** Run relevant automated tests, static checks, builds, migrations in a safe environment, and manual/browser checks where applicable.
6. **Fix:** Diagnose and fix failures caused by the implementation. Do not hide or bypass failures.
7. **Retest:** Re-run affected tests and the relevant broader regression suite.
8. **Document:** Update architecture, API, database, environment, task, and test documentation as needed.
9. **Report:** Produce a phase report containing changes, files, commands, test results, evidence, known limitations, and blockers.
10. **Gate:** Evaluate every exit criterion. Mark each as PASS, FAIL, or BLOCKED with evidence.
11. **Continue:** Only if all mandatory exit criteria pass, mark the phase complete and read the next phase in this same reference before starting it.

## 2.4 Autonomous continuation

After a phase passes:
- Update the current phase and next phase in the implementation tracker.
- Re-read the next phase's full instructions and prerequisites.
- Start the next phase without waiting for the user, unless a decision, credential, approval, or destructive action requires user input.
- Continue sequentially until all phases are complete or a genuine blocker is reached.
- Do not stop after producing a plan if implementation is expected and the environment allows implementation.
- Do not ask the user to approve routine, reversible code changes that are already within this directive.
- Ask before destructive operations, irreversible data changes, production deployment, spending money, creating paid external resources, or changing provider account settings.

## 2.5 Bounded troubleshooting

If a test or implementation fails:
- Read the full error and identify the actual root cause.
- Reproduce the issue when practical.
- Make a targeted fix.
- Re-run the failing test and relevant regression tests.
- After three meaningful unsuccessful attempts at the same issue, stop repeating speculative fixes. Document evidence, attempted fixes, likely cause, and the exact decision/access needed.
- Never claim success because a command was started; verify its exit status and output.

## 2.6 Progress and continuity files

Maintain these files throughout the project:
- `docs/implementation/IMPLEMENTATION_PLAN.md` — phases, dependencies, status, and order.
- `docs/implementation/TASKS.md` — actionable task checklist and current progress.
- `docs/implementation/DECISIONS.md` — architecture and product decisions with rationale.
- `docs/testing/TEST_REPORT.md` — test commands, results, failures, and evidence.
- `docs/architecture/ARCHITECTURE.md` — current system architecture.
- `docs/architecture/DATABASE_DESIGN.md` — schema and data relationships.
- `docs/architecture/API_SPECIFICATIONS.md` — API contracts and conventions.
- `docs/deployment/DEPLOYMENT.md` — environment and deployment procedures.

At the end of each session, update the tracker so another agent/session can resume without guessing.

## 2.7 Safety and scope rules

Never:
- Commit secrets or real tokens.
- expose credentials in frontend code or logs.
- run destructive database commands against real data without explicit approval and a verified backup.
- use `migrate:fresh`, reset, truncate, force-push, or destructive git commands unless explicitly authorized for the exact operation.
- replace existing architecture or dependencies without inspecting the impact and documenting the reason.
- invent test results, API access, account data, or deployment status.
- make production changes without explicit approval.
- weaken authentication, authorization, validation, or security checks to make a demo work.

---

# 3. Repository and Documentation Structure

Use this as the target organization. Adapt to an existing repository where necessary; do not duplicate or move working code merely to match a tree.

```text
advertising-platform/
├── docs/
│   ├── reference/
│   │   ├── MASTER_PROJECT_REFERENCE.md
│   │   └── UI_UX_DESIGN_REFERENCE.md
│   ├── architecture/
│   │   ├── ARCHITECTURE.md
│   │   ├── DATABASE_DESIGN.md
│   │   └── API_SPECIFICATIONS.md
│   ├── implementation/
│   │   ├── IMPLEMENTATION_PLAN.md
│   │   ├── TASKS.md
│   │   └── DECISIONS.md
│   ├── testing/
│   │   ├── TEST_REPORT.md
│   │   ├── FINAL_QA_REPORT.md
│   │   └── SECURITY_AUDIT.md
│   └── deployment/
│       ├── DOCKER_GUIDE.md
│       ├── VPS_SETUP.md
│       └── DEPLOYMENT.md
├── frontend/                 # Angular application
├── backend/                  # Laravel application
├── docker/
│   ├── nginx/
│   └── php/
├── docker-compose.yml
├── docker-compose.production.yml
├── .gitignore
└── README.md
```

If the user-supplied UI/UX reference is available, place its complete contents at `docs/reference/UI_UX_DESIGN_REFERENCE.md`. Do not fabricate or summarize missing source material. If unavailable, record that it is missing and ask for it only when needed to implement the referenced design faithfully.

---

# 4. Phase 0 — Repository Audit and Project Planning

## Objective
Understand the current repository and environment before changing code. Establish a reliable baseline and a phase-by-phase implementation plan.

## Instructions
1. Inspect the full repository structure, package manifests, Composer files, existing code, environment examples, Docker files, database setup, and documentation.
2. Inspect git status and current branch. Preserve uncommitted user work.
3. Determine whether Angular and Laravel projects already exist or need to be initialized.
4. Identify current versions and compatibility constraints for Angular, Node.js, PHP, Laravel, PostgreSQL, Redis, Docker, and Composer.
5. Identify existing authentication, API conventions, design system, tests, and deployment configuration.
6. Identify missing files, duplicate implementations, insecure patterns, abandoned code, and architectural conflicts.
7. Do not rewrite or delete anything in this audit phase.
8. Create/update the architecture overview, implementation plan, task tracker, and decisions log.
9. Define phase dependencies and acceptance criteria.

## Deliverables
- Repository audit report
- Current architecture summary
- Risks and blockers
- Proposed implementation plan
- Prioritized task tracker
- Initial architecture and database design drafts

## Exit criteria
- Repository and baseline are understood.
- Existing user work is preserved.
- Stack and runtime compatibility are documented.
- All phases are sequenced with dependencies and measurable acceptance criteria.
- No unapproved destructive changes were made.

---

# 5. Phase 1 — Local Infrastructure, Docker, and Application Skeleton

## Objective
Create or stabilize the local development foundation for Angular, Laravel, PostgreSQL, Redis, and Nginx.

## Instructions
1. Create or verify the Angular application in `frontend/`.
2. Create or verify the Laravel application in `backend/`.
3. Configure Docker Compose services for the local environment:
   - Nginx
   - PHP-FPM / Laravel
   - PostgreSQL
   - Redis
   - Angular development environment if useful for the chosen workflow
   - Laravel queue worker
   - Laravel scheduler process
4. Use persistent Docker volumes for local database data where appropriate.
5. Configure internal service networking and health checks.
6. Add `.env.example` files with placeholders only.
7. Ensure the Laravel backend connects to PostgreSQL and Redis through Docker service names, not hard-coded host addresses.
8. Configure Nginx routing for the backend and document local frontend/backend URLs.
9. Add safe startup, shutdown, logs, and troubleshooting instructions.
10. Ensure migrations can run against the local development database.
11. Keep production-specific settings separate from local settings.
12. Do not deploy or provision paid infrastructure in this phase.

## Deliverables
- Working local Docker Compose setup
- Angular starter application
- Laravel starter API
- PostgreSQL and Redis connectivity
- Queue and scheduler process configuration
- `.env.example` files
- Docker setup guide

## Exit criteria
- All required local services start successfully.
- Angular can load locally.
- Laravel health endpoint responds.
- Laravel can connect to PostgreSQL and Redis.
- Queue worker and scheduler can start.
- No secrets are committed.
- Setup is reproducible from documented instructions.

---

# 6. Phase 2 — Design System and Angular Application Foundation

## Objective
Build a reusable, production-quality Angular UI foundation before implementing detailed product screens.

## Instructions
1. Read `docs/reference/UI_UX_DESIGN_REFERENCE.md` if present.
2. Define design tokens for Light Mode and Dark Mode.
3. Implement theme initialization:
   - Use system preference on first visit.
   - Respect a saved user choice on subsequent visits.
   - Provide a visible theme toggle.
   - Avoid flashes of the wrong theme during startup.
4. Build reusable components for:
   - Buttons and icon buttons
   - Inputs, selects, checkboxes, and form fields
   - Cards and metric summaries
   - Tables and pagination
   - Tabs and navigation
   - Modals/dialogs and drawers
   - Dropdowns and menus
   - Toasts/notifications
   - Tooltips
   - Loading/skeleton states
   - Empty, error, and no-permission states
5. Establish typography, spacing, responsive breakpoints, icon usage, and interaction states.
6. Implement the main application shell and sidebar/top navigation.
7. Add route placeholders for the product areas without faking backend functionality.
8. Ensure all components are Angular-native and compatible with the selected Angular version.
9. Add accessibility and keyboard behavior.
10. Avoid excessive animation and unnecessary dependencies.
11. Verify theme behavior across charts, tables, forms, overlays, and navigation.

## Deliverables
- Angular design system
- Theme service and persistent theme preference
- Responsive application shell
- Reusable component library
- Route skeleton for main product sections
- UI implementation notes

## Exit criteria
- Light and Dark Modes work consistently.
- Theme preference persists and system preference is respected initially.
- Navigation and layout work on desktop, tablet, and mobile.
- Reusable components have consistent states and accessibility behavior.
- No React-only components have been introduced.
- Angular build and relevant tests pass.

---

# 7. Phase 3 — Authentication, Users, Roles, and Permissions

## Objective
Implement secure first-party authentication and user/role management in Laravel.

## Instructions
1. Implement authentication using Laravel's first-party capabilities and an appropriate Sanctum-based approach for the Angular first-party application.
2. Do not use Clerk, Supabase Auth, Firebase Auth, or another external authentication provider.
3. Implement secure login, logout, session/token lifecycle, password hashing, and password reset.
4. Configure CORS, CSRF, cookie/session settings, and trusted origins correctly for local and staging environments.
5. Implement user invitation/creation by email for application users.
6. Distinguish application user invitations from creating external email mailboxes. This application does not provision mailboxes.
7. Implement roles and granular permissions appropriate to the product, such as Owner, Admin, Analyst, and Viewer. Keep the model extensible.
8. Enforce permissions in Laravel policies/middleware/services, not only in Angular.
9. Add account profile and user-management screens.
10. Add audit events for authentication and sensitive user/permission changes.
11. Protect all private routes and APIs.
12. Add rate limiting and safe error handling for authentication endpoints.

## Deliverables
- Authentication API
- User and role/permission schema
- Authorization policies
- Angular authentication flow and route guards
- User-management screens
- Invitation flow
- Authentication/security tests

## Exit criteria
- Unauthenticated users cannot access protected resources.
- Role and permission checks are enforced by the backend.
- User invitation and account lifecycle work as documented.
- Passwords and sessions/tokens are handled securely.
- Security and automated tests pass.

---

# 8. Phase 4 — Developer API Configuration Center

## Objective
Build the administrative center where provider developer application credentials and integration settings are configured securely.

## Instructions
1. Design a provider-agnostic configuration model for Meta Ads, Google Ads, and TikTok Ads.
2. Support provider-specific credential fields only when required by current official documentation.
3. Store client IDs, client secrets, developer tokens, app IDs, redirect URIs, API versions, and other provider settings as applicable.
4. Encrypt sensitive fields at rest.
5. Never return decrypted secrets to Angular after saving.
6. Show masked values and configuration status.
7. Implement create, update, validate/test, enable/disable, and rotate workflows as appropriate.
8. Restrict credential management to authorized administrators.
9. Add audit logs for credential configuration changes, without recording secret values.
10. Support separate development/staging/production configuration through environment or deployment secrets. Do not mix credentials across environments.
11. Document each provider's required configuration, approval status, callback URL, scopes, and setup steps.
12. Do not pretend that a connection test is successful if it cannot be performed.

## Deliverables
- Provider configuration schema and migrations
- Secure credential service
- Admin configuration APIs
- Angular Developer API Settings screens
- Masked secret presentation
- Audit logging
- Provider setup documentation

## Exit criteria
- Authorized admins can configure provider credentials.
- Sensitive values are encrypted and never exposed in API responses.
- Unauthorized users cannot read or modify configurations.
- Configuration changes are audited.
- Validation status is truthful and documented.

---

# 9. Phase 5 — Official Advertising Provider Integrations

## Objective
Implement real direct integrations with official advertising APIs. Each provider must be implemented and verified against its current official documentation.

## General integration requirements
1. Research the current official developer documentation before coding each provider.
2. Record documentation URLs, API version, required permissions/scopes, approval requirements, and known limitations.
3. Implement official OAuth authorization and callback handling.
4. Validate state, redirect URI, code exchange, and provider errors.
5. Store tokens securely on the backend.
6. Implement refresh-token/access-token renewal where supported.
7. Discover available advertising accounts and relevant business assets.
8. Let the user select the accounts to connect.
9. Persist provider/account identifiers and safe account metadata.
10. Implement disconnect, reconnect, token-expired, revoked-access, and permission-changed states.
11. Handle pagination, rate limits, transient errors, and provider-specific response formats.
12. Keep all provider secrets and API calls on the backend.
13. Add integration tests using mocks and live smoke tests only when real credentials and approval are available.
14. Never fabricate successful live integration results.

## 9.1 Meta Ads
- Use official Meta developer and Marketing API documentation.
- Configure the Meta app and required permissions.
- Implement the appropriate OAuth flow.
- Discover accessible ad accounts and associated business assets as permitted.
- Store account identifiers and metadata.
- Implement initial account connection and health status.
- Document app review and permission limitations.
- Verify live access only with a properly configured app and authorized account.

## 9.2 Google Ads
- Use official Google Ads API documentation.
- Configure OAuth credentials and developer token requirements.
- Implement manager/customer account discovery as appropriate.
- Handle login customer ID and customer account relationships where relevant.
- Respect API access level, developer-token approval, quotas, and required scopes.
- Retrieve account metadata and implement a verified connectivity test.
- Document any approval or access restrictions.

## 9.3 TikTok Ads
- Use official TikTok for Business / Marketing API documentation.
- Configure the approved developer application and required scopes.
- Implement OAuth and advertiser/account discovery as supported.
- Store advertiser identifiers and metadata.
- Handle provider-specific token lifecycle and API limitations.
- Verify access only when the application and account have the required permissions.

## 9.4 Future provider extensibility
Create clear provider adapter/service boundaries so LinkedIn Ads, Snapchat Ads, and Pinterest Ads can be added later without rewriting the core account, sync, and reporting architecture.

## Deliverables
- Provider adapter architecture
- OAuth flows and callbacks
- Connected advertising account management
- Token lifecycle handling
- Provider connection health/status
- Provider setup and troubleshooting documentation
- Automated tests and live verification evidence where available

## Exit criteria
- Each implemented provider follows official documentation.
- OAuth state and callback security are tested.
- Tokens are protected and refresh/revocation behavior is handled.
- Account discovery and selection work for authorized test accounts.
- Provider-specific limitations are documented.
- No unsupported provider is marked complete.

---

# 10. Phase 6 — Advertising Data Engine and Synchronization

## Objective
Build a reliable background data pipeline that retrieves, stores, normalizes, and reconciles advertising data.

## Instructions
1. Design raw-source storage and normalized reporting tables.
2. Preserve provider identifiers and source metadata.
3. Define common entities such as:
   - Connected advertising account
   - Campaign
   - Ad group/ad set
   - Ad
   - Reporting period/date
   - Performance metric record
   - Sync job and sync run
4. Map provider-specific entities carefully. Do not assume that campaign hierarchy or metric definitions are identical across platforms.
5. Preserve currency, timezone, attribution settings, reporting granularity, and source timestamps.
6. Keep raw provider data separate from normalized records.
7. Implement Laravel queued jobs for initial sync, incremental sync, historical backfill, and scheduled refresh.
8. Implement pagination and provider-specific rate-limit handling.
9. Use retry policies with exponential backoff and sensible maximum attempts.
10. Use idempotency keys/unique constraints to prevent duplicates.
11. Use locks or another safe mechanism to prevent overlapping syncs for the same account and period.
12. Track job progress, last successful sync, errors, retry count, and data freshness.
13. Support manual sync and scheduled sync.
14. Implement reconciliation to detect missing, changed, or inconsistent source data.
15. Make failures visible and recoverable.
16. Never turn missing values into zero unless the source semantics justify it.
17. Add indexes and query strategies for reporting workloads.
18. Add tests for pagination, retries, duplicates, partial failures, timezones, currency, and normalization.

## Deliverables
- Raw data storage strategy
- Normalized database schema
- Provider-to-normalized mapping
- Queue jobs and scheduler tasks
- Sync status and monitoring APIs
- Manual and scheduled sync flows
- Reconciliation and recovery procedures
- Data pipeline tests

## Exit criteria
- Sync jobs are idempotent and recoverable.
- Pagination and rate limits are handled.
- Raw and normalized data can be traced to their source.
- Currency/timezone/attribution metadata is preserved.
- Sync failures and stale data are visible.
- Tests cover critical data integrity cases.

---

# 11. Phase 7 — Dashboard, Campaigns, and Analytics

## Objective
Build functional reporting screens using verified backend data.

## Instructions
1. Define metric contracts and calculation rules before displaying analytics.
2. Build the overview dashboard with date range and account/provider filters.
3. Show only data the user is authorized to access.
4. Display clear loading, empty, stale-data, partial-data, and error states.
5. Provide campaign listing and campaign detail views.
6. Support useful filtering, sorting, pagination, and drill-down where the APIs provide the required data.
7. Implement charts and tables for metrics that are genuinely available.
8. Separate provider-reported metrics from platform-calculated metrics.
9. Display currency and timezone context.
10. Make attribution differences visible when comparing providers.
11. Avoid adding metrics that cannot be reliably sourced or defined.
12. Ensure all analytics components work in both Light and Dark Modes.
13. Optimize API queries and avoid downloading large datasets unnecessarily.
14. Add tests for filters, date boundaries, aggregation, authorization, and metric calculations.

## Deliverables
- Overview dashboard
- Advertising analytics pages
- Campaign list and detail pages
- Reusable chart/table components
- Metric definitions and calculation documentation
- Responsive UI and tests

## Exit criteria
- Dashboard values come from the backend data engine.
- Filters and date ranges work correctly.
- Metric definitions and data freshness are visible.
- No fabricated or hard-coded production metrics remain.
- Authorization and regression tests pass.

---

# 12. Phase 8 — Billing and Payment Information

## Objective
Present provider billing and payment information accurately, without confusing it with campaign performance spend.

## Instructions
1. Research the current official billing/payment API capabilities for each provider.
2. Identify which billing fields are available, under what scopes, and for which account types.
3. Implement provider-specific billing adapters only for supported data.
4. Model invoices, transactions, balances, payment statuses, credits, taxes, refunds, and adjustments only where the provider exposes them.
5. Clearly distinguish ad spend from billed/charged amounts and outstanding balances.
6. Display source, currency, reporting period, and last-updated time.
7. Handle unavailable or restricted billing data with explicit states.
8. Restrict sensitive billing details to authorized roles.
9. Do not collect or store payment-card details unless a separately approved requirement and compliant payment architecture exist.
10. Add tests for currency, status mapping, missing fields, and access control.

## Deliverables
- Billing data model
- Provider billing adapters where supported
- Billing APIs and UI
- Data availability/limitation documentation
- Tests

## Exit criteria
- Billing values are sourced from official APIs.
- Spend and billing are not conflated.
- Unsupported fields are not fabricated.
- Permissions and sensitive data handling are tested.

---

# 13. Phase 9 — AI Integrations Center

## Objective
Allow administrators to configure supported AI API providers and allow authorized product features to use them through a secure backend abstraction.

## Instructions
1. Define a provider-neutral AI service interface.
2. Implement provider adapters for OpenAI, Anthropic Claude, and Google Gemini where credentials and current APIs permit.
3. Allow future compatible providers to be added without coupling business logic to one vendor.
4. Store API keys encrypted on the backend.
5. Never send provider secrets to Angular.
6. Support model selection and safe configuration.
7. Add connection tests using official provider endpoints where supported.
8. Track usage, latency, errors, and cost metadata when the provider exposes it.
9. Define timeouts, retries, rate limits, and budget controls.
10. Ensure AI features have explicit authorization and data-access boundaries.
11. Do not send sensitive advertising account data to AI providers without an explicit product decision and appropriate user/admin controls.
12. Do not claim that ChatGPT consumer subscriptions provide API credits.
13. Do not scrape or reuse browser sessions for consumer AI products.
14. Add clear status and error states in the AI Integrations UI.

## Deliverables
- AI provider abstraction
- Secure provider configuration
- Provider adapters
- Usage/error tracking
- Admin UI
- Security and integration tests
- Provider setup documentation

## Exit criteria
- Credentials remain backend-only and encrypted.
- Provider calls use documented APIs.
- Model selection and connection status work.
- Usage/errors are tracked where available.
- Sensitive data boundaries are documented and enforced.

---

# 14. Phase 10 — Looker Studio Integration

## Objective
Allow authorized reporting data from the platform to be consumed by Looker Studio securely.

## Instructions
1. Research current official Looker Studio Community Connector and data-source requirements.
2. Choose and document the most maintainable supported integration path.
3. Use the platform's normalized data API as the source.
4. Define a stable schema for dimensions and metrics.
5. Support date ranges, provider/account filters, and pagination where applicable.
6. Implement secure authentication and authorization for connector access.
7. Do not expose provider tokens or raw secrets to Looker Studio.
8. Document metric definitions, currency behavior, timezone, and attribution limitations.
9. Handle schema evolution and connector errors.
10. Test with a real Looker Studio connection when credentials and access are available.

## Deliverables
- Integration architecture
- Secure reporting endpoint or connector
- Dimension/metric schema
- Setup guide
- Verification report

## Exit criteria
- Connector reads authorized platform data.
- Authentication and access control work.
- Metric definitions and limitations are documented.
- No provider secrets are exposed.
- Real connection is verified when the required external setup is available.

---

# 15. Phase 11 — Full QA, Security, Performance, and Regression

## Objective
Validate the whole product as a coherent system before production deployment.

## Instructions

### Functional QA
- Test registration/invitation/login/logout/password reset as implemented.
- Test roles and permissions across frontend and backend.
- Test developer credential configuration.
- Test each available OAuth flow and connected-account lifecycle.
- Test account discovery and selection.
- Test initial, incremental, and manual synchronization.
- Test dashboard filters, campaigns, and reporting.
- Test billing availability and restrictions.
- Test AI provider configuration and calls.
- Test Looker Studio access where configured.

### Security QA
- Review authentication and session/token handling.
- Review authorization and tenant/account boundaries.
- Review OAuth state, redirect URI, and callback validation.
- Review encryption and secret exposure risks.
- Review CORS, CSRF, rate limiting, input validation, SQL injection, XSS, and mass assignment.
- Review file/log/error exposure.
- Review dependency vulnerabilities.
- Verify that secrets are absent from git history/current tracked files where feasible.
- Verify safe handling of provider API errors and tokens.
- Verify audit logging for sensitive actions.

### Performance QA
- Review slow database queries and missing indexes.
- Check N+1 queries.
- Test queue throughput and sync concurrency controls.
- Review large reporting date ranges and pagination.
- Check Angular bundle size and route loading.
- Check API response times under representative local/staging load.
- Verify caching does not leak data across users or accounts.

### UX and compatibility QA
- Test Light and Dark Modes.
- Test desktop, tablet, and mobile layouts.
- Test keyboard navigation and focus states.
- Test empty, loading, error, stale, and partial-data states.
- Verify English UI consistency.
- Test current supported browsers.

### Required evidence
For every test, record:
- Test name
- Environment
- Command or procedure
- Expected result
- Actual result
- PASS / FAIL / BLOCKED
- Relevant logs or evidence
- Follow-up issue if applicable

Do not report a blocked external-provider test as passed merely because a mock test passed.

## Deliverables
- Full test report
- Security audit
- Performance findings
- Regression results
- Final QA report
- Prioritized remediation list

## Exit criteria
- Critical and high-severity defects are fixed or explicitly accepted by the user.
- Required automated tests pass.
- Security review is complete.
- Theme and responsive QA pass.
- External integration limitations are clearly documented.
- No false claims of live-provider verification.

---

# 16. Phase 12 — Staging, VPS, and Production Deployment

## Objective
Prepare and deploy the application through a safe, repeatable process.

## Instructions
1. Prepare a staging deployment plan for the Laravel backend in a PHP-compatible HTTPS environment.
2. Deploy the Angular frontend preview to Vercel.
3. Configure frontend environment variables to point to the staging API.
4. Prepare production Docker Compose configuration for the VPS.
5. Configure Nginx, PHP-FPM, PostgreSQL, Redis, queue workers, and scheduler.
6. Configure HTTPS/TLS and domain routing.
7. Configure production secrets outside source control.
8. Configure database migrations and a safe release procedure.
9. Establish backup and restore procedures for PostgreSQL and required persistent files.
10. Configure logs, health checks, uptime monitoring, and queue monitoring.
11. Define queue worker restart and deployment procedures.
12. Configure CORS, cookies, CSRF, trusted hosts, and secure headers for production domains.
13. Document rollback steps.
14. Do not deploy to production, change DNS, create paid resources, or incur costs without explicit user approval.
15. Do not claim a deployment is live until it has been verified externally.

## Deliverables
- Staging deployment guide
- Vercel frontend configuration
- Production Docker Compose configuration
- VPS setup guide
- Environment variable inventory
- Backup/restore and rollback guide
- Deployment verification report

## Exit criteria
- Staging frontend and backend communicate securely over HTTPS.
- Production configuration is reproducible and documented.
- Database, Redis, queues, and scheduler are configured.
- Backups and rollback procedures are documented and tested where feasible.
- Production deployment occurs only after explicit approval.
- Deployment status is backed by actual verification.

---

# 17. Final Project Acceptance Checklist

The project is complete only when the relevant items below are verified:

## Architecture and infrastructure
- [ ] Angular + TypeScript frontend
- [ ] Laravel + PHP backend
- [ ] PostgreSQL primary database
- [ ] Redis cache/queues
- [ ] Docker-based local environment
- [ ] Separate Laravel staging/production hosting
- [ ] Angular Vercel preview
- [ ] Documentation and environment examples

## Authentication and access
- [ ] First-party Laravel authentication
- [ ] No Clerk/Supabase Auth/Firebase Auth
- [ ] User invitations and role management
- [ ] Backend-enforced authorization
- [ ] Audit logging for sensitive actions

## Advertising integrations
- [ ] Developer API Configuration Center
- [ ] Official Meta Ads API integration
- [ ] Official Google Ads API integration
- [ ] Official TikTok Ads API integration
- [ ] Secure OAuth and token lifecycle
- [ ] Account discovery and selection
- [ ] Connection health and reconnect flows

## Data and reporting
- [ ] Raw source data traceability
- [ ] Normalized reporting model
- [ ] Currency/timezone/attribution metadata
- [ ] Reliable sync jobs and retries
- [ ] Pagination and rate-limit handling
- [ ] Idempotency and reconciliation
- [ ] Dashboard and campaign analytics
- [ ] No fabricated metrics

## Additional integrations
- [ ] Billing data where officially available
- [ ] AI Integrations Center
- [ ] Looker Studio integration

## Product quality
- [ ] English-only UI
- [ ] Light Mode and Dark Mode
- [ ] Responsive layouts
- [ ] Accessibility and keyboard navigation
- [ ] Automated tests and regression suite
- [ ] Security audit
- [ ] Performance review
- [ ] Deployment and recovery documentation

A checkbox may be marked complete only when supported by implementation and verification evidence. If an item depends on external credentials, approvals, or services not yet supplied, mark it BLOCKED and explain exactly what is required.

---

# 18. Start / Resume Command for the AI Agent

Use this instruction whenever beginning or resuming work:

> Read `docs/reference/MASTER_PROJECT_REFERENCE.md` completely before making changes. This file is the single source of truth for project requirements, architecture, execution rules, phase order, and acceptance criteria.
>
> Inspect the repository, git status, implementation tracker, latest phase report, and current environment. Determine the first phase that is not fully complete. Verify that all previous phases passed their exit criteria. Do not assume a phase is complete merely because files exist.
>
> Execute that phase using the Mandatory AI Execution Protocol: inspect, plan, implement, test, fix, retest, document, report, and evaluate its exit gate. Preserve existing work and follow all security and scope restrictions.
>
> When a phase passes, update the trackers, read the next phase in this same document, and continue automatically. Repeat phase by phase until all phases are complete or a genuine blocker requires user input.
>
> Do not stop after planning when implementation is possible. Do not skip phases. Do not fabricate data, tests, integrations, approvals, or deployment results. Ask the user only when a required credential, external approval, product decision, destructive operation, production deployment, or paid resource requires their input.
>
> At the end of the session, clearly report the current phase, completed work, test evidence, blockers, and the exact next action. Update the progress files so the next session can resume reliably.

---

# 19. Phase Completion Report Template

Use this template at the end of every phase:

```markdown
# Phase [N] — [Name] Completion Report

## Status
PASS / FAIL / BLOCKED

## Summary
[What was completed and what remains.]

## Implementation
- [Feature/change]
- [Feature/change]

## Important files changed
- `path/to/file` — [reason]

## Database/API changes
[Describe migrations, endpoints, contracts, or state that none were required.]

## Verification
| Check | Command / Procedure | Result | Evidence |
|---|---|---|---|
| [Check] | [Command] | PASS/FAIL/BLOCKED | [Output or report reference] |

## Security considerations
[Relevant review and findings.]

## Known limitations and blockers
[Be explicit. Do not hide external dependencies.]

## Exit criteria
- [PASS/FAIL/BLOCKED] [Criterion] — [Evidence]

## Tracker updates
[Files updated and current phase status.]

## Next phase
[Next phase number and name, or the exact blocker.]
```

**End of master reference.**
