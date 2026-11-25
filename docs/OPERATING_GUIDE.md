# Current correction notice

Snapchat analytical support is unavailable. The implemented bare account stats endpoint is spend-inspection only; canonical imports fail without advancing last-success, and old Snap analytical rows/archives are withheld without editing them. See SNAP_ACCOUNT_SOURCE_CORRECTION.md. This supersedes earlier descriptions implying account-level purchase/delivery support or fixed48h conversion finality. Other provider/typed-lead functionality is not expanded or newly certified. Cross-provider portfolios remain blocked.

# Deployed state and access rules (verified 25 November 2026)

- Production at https://ad-platform-alpha.vercel.app runs main at commit a88df8e (Vercel deployment Ready). Production was never given synthetic data; no provider connection was made.
- Roles and platform filters on production were not re-verified for this note; see the role-management and platform-filter changes in the repository history.
- Vercel preview deployments use the same database as production. Do not exercise write flows on a preview.
- Not covered by this document: backups, monitoring, scheduled sync and any real provider connection. None of these was inspected.
- Features on unmerged feature branches (not deployed): Team page role change and member removal (feature/team-role-management); Pinterest async long-history reports (feature/pinterest-async-reports, fixture-tested only).

# AdPlatform local operating guide

Status: local implementation in progress, 24 November 2026. Read SOURCE_CHECKPOINT_2026_11_24.md first for the consolidated successful-use guide and current limitations. No deployment or current production-state verification is included in this development work. No live advertising account, provider OAuth approval or paid service was used to verify these changes.

Verification environment and remaining implementation: see CHECKPOINT_VERIFICATION.md. This is an interim delivery, with continuation beyond this package.

## Start locally

1. Backend: install Composer dependencies, copy backend/.env.example to backend/.env, set a local PostgreSQL connection, generate APP_KEY, and run migrations. Keep credentials outside source control.
2. First owner: set ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD in the local environment before seeding. No built-in owner password is supplied and existing accounts are not overwritten.
3. Frontend: run npm ci in frontend/, then npm run build or the development server. Configure its API route/proxy for your local backend.
4. Optional discrete solver: read EXTERNAL_SOLVER.md, install the pinned packages in an isolated Python environment and set OR_SOLVER_PYTHON to its absolute interpreter. Empty default fails closed, no silent continuous fallback. The native container does not automatically install this runtime.
5. Backups require PostgreSQL client tools compatible with the server. No backup/client binaries are bundled in source archives.

## Verification

Current local synthetic test checkpoint: on the experimental feature branch feature/pinterest-async-reports (25 November 2026), 686 backend tests (6454 assertions) and 222 Angular tests (the Angular count was measured on feature/team-role-management). Counts for main were not re-measured here. The suites were run on throwaway local PostgreSQL databases with synthetic data, and a full backend run wipes the database it runs on. Run it only on a throwaway database with APP_ENV=testing and the bootstrap-owner variables unset. These counts describe this checkpoint, not permanent guarantees or live-integration evidence. Re-run after configuration, dependency, schema or runtime changes:

- backend/: php vendor/phpunit/phpunit/phpunit, using PostgreSQL per phpunit.xml. The backup/restore tests need compatible pg_dump/psql on PATH.
- frontend/: npm test -- --watch=false, then npm run build.
- Provider fixtures test bounded request/response contracts, not actual authorization or data availability.
- Visual checks used synthetic records on desktop and phone layouts. No real customer or campaign data was used.

## Main workflows

### Observed evidence

Evidence & Diagnostics shows actual KPIs/daily series, model-based forecast intervals, anomaly signals and observed funnel diagnostics separately. Completed daily windows use verified account IANA timezones. Unknown/incompatible scope, gaps or operand problems abstain rather than silently substituting data. Audience/creative matrices are entity-scoped proxies, not canonical people/creative identity resolution or calibrated saturation thresholds.

### Platform portfolio OR

The platform portfolio screen requires explicit objective and outcome basis. Its cross-provider allocation path currently abstains; historical assumed/fitted route descriptions are not evidence of a currently supported cross-provider recommendation. Source-account-currency arithmetic is descriptive, not unique customers or verified revenue. Source-scoped entity scenarios below remain separate. Budget/horizon/currency/quality gates remain explicit. Actual historical totals and same-budget modeled baseline are separate. Fitted mode selects among validated monotone concave candidates; observational curves do not identify causal budget lift. See OR_ENGINE.md for historical assumed-route details and MATH_SPEC_COVERAGE.md for current extensions.

### Entity scenarios

Choose campaign, ad-set or ad level, objective and explicit 30-day budget. Every selected entity needs complete comparable history and a valid fit. Value/conversion objectives, typed leads, bounded portfolio ratios and explicit value/CPA/ROAS targets retain distinct semantics. Do not interpret reported conversions as unique customers or reported value as profit.

Continuous entity minima are mandatory. Discrete minima are minimum IF ACTIVE, with pause, explicit step and maximum-active count. Finite-menu optimum is not continuous optimum. Unknown runtime/model contracts or failed certificates return no accepted allocation.

Inspect candidate holdout/penalty evidence, response/support chart, modeled baseline/custom comparison, sensitivity and separate robust scenario. Sensitivity stability is engineering perturbation, stress is fixed-allocation RMSE scaling, and robust bounds are explicit user assumptions. None is allocation-confidence probability.

### Stored scenarios

Load owner-scoped history, then open an archived result. Its original scope/inputs are retained and labeled, not replaced by current form settings. Replays use archived curves/constraints with current installed runtime, not current metrics. Base and robust replay are distinct. Ties and changed solver environments can alter allocation. Archive checksums detect accidental changes, not privileged database tampering; legacy results may be unsealed.

Fixed questions explain stored evidence without an LLM call. The HTML report is a private local download with print styling, not publication or sharing. It is deterministic stored evidence, not an independent audit or current forecast. General free-form AI analyst Q&A remains unfinished.

### First-party sources and attribution

Typed conversion definitions and manual aggregates are explicitly user-declared, not provider-verified. Lead optimization requires a selected owned lead definition and matching entity/day spend population. See TYPED_CONVERSION_SOURCES.md.

Click-journey attribution allocates only explicitly supplied already-deduplicated first-party journeys. Five-rule comparison uses the same population; rule disagreement is not confidence. No identity resolution, event feed or data-driven attribution model is implemented. Never apply the rules to summed provider conversion totals.

## Boundaries and remaining work

- No campaign execution, account setup, spending or deployment is included in these changes.
- No adapter is verified against a live account. Request/mapping coverage and provider-specific gates differ. See PROVIDER_IMPLEMENTATION_MATRIX.md and PROVIDER_SOURCES.md.
- Canonical audience/placement identities, all-provider creative mapping/effective history, automatic event feeds/identity/cross-source deduplication, trained data-driven attribution, calibrated saturation threshold intervals, general LP/NLP/QP routing and full AI analyst workflow remain incomplete.
- Team sharing and private owner archive access are separate. Owner archive features do not grant other users access.
- Historical documents under docs/archive and older numbered engineering notes are context, not current production-state evidence. Current coverage is in MATH_SPEC_COVERAGE.md and its latest sections.

## Canonical/evidence continuation milestone

See CANONICAL_CREATIVE_IDENTITY.md and CANONICAL_DATA_MILESTONE.md for exact provider-object creative identity/observations, strict metric hierarchy and historical read-only audit. FIRST_PARTY_EVENTS.md documents separate manual event/click evidence with stored rule attribution. These features do not establish source authorization, cross-platform identity, historical creative/day attribution or automatic CRM ingestion. Current clean-source verification and frozen-versus-later package distinction are in CANONICAL_MILESTONE_VERIFICATION.md.
