## Superseding Snapchat correction

The later correction in SNAP_ACCOUNT_SOURCE_CORRECTION.md supersedes all earlier Snapchat account-level delivery/purchase and analytical availability descriptions. Only separate source-spend inspection remains; canonical Snap analytics, typed-spend reuse and archived decisions are unavailable. This is a lost capability, not merely pending live validation. Existing artifacts and historical rows are not repaired or rewritten. The remaining guide describes unchanged non-Snap functionality and historical verification.

# Source checkpoint and successful-use guide

24 November 2026. This package consolidates completed local work after the previously delivered d871b22 package, which is unchanged. Runtime milestone: 13164f6. Later commits at this boundary change documentation/export rules only. This is not completion of the original specification, deployment approval or live-provider verification.

## What is useful now

1. **Original-provider fetch boundaries:** Google synchronous search, TikTok page traversal and Snapchat account/day reads reject malformed, incomplete or unsupported envelopes without accepting partial metrics or advancing last-success state. Exact limits and tests: ORIGINAL_PROVIDER_FETCH_BOUNDARY.md. Meta's separate traversal contract is not silently generalized. Inventory completeness and provider permission are not proven.
2. **Outcome-semantic separation:** delivery/spend and source-reported outcome arithmetic remain distinct. A normalization marker does not prove common event population, value, attribution or timing. Incompatible pooled business outcomes are withheld. Read OUTCOME_SEMANTIC_BOUNDARY.md.
3. **Objective/estimand preflight:** portfolio intent starts blank; neither objective nor business meaning is inferred from a profile, example or metric column. Source-account-currency descriptive groups are not allocation recommendations. Preflight and refusals are archived with the source. Read OBJECTIVE_ESTIMAND_PREFLIGHT.md.
4. **Successful declared-lead scenario interaction:** one owned account, one immutable declared lead definition, campaign level, continuous MAXIMIZE_LEADS, explicit budget, complete matched 30-day observations and validated fits. Current, optimized and custom are compared on the same archived models. Read GUIDED_LEAD_SCENARIOS.md for exact endpoint and checks.

## Run locally

Use OPERATING_GUIDE.md for setup. Install locked backend and frontend dependencies; configure a local PostgreSQL database and APP_KEY outside source control; migrate. Set your own bootstrap owner identity before seeding, with no built-in owner password. Optional discrete solver and compatible database-client requirements are in EXTERNAL_SOLVER.md and the operating guide. Dependencies, runtimes, credentials, database contents and build artifacts are not bundled. Deployment configuration is unverified and must not be treated as a production recipe.

## Use the successful lead path

1. In Evidence & Diagnostics, click **Load owned accounts** in Campaign lead allocation. No account, definition, objective or budget is selected by default.
2. Choose the account and a `lead` definition. Definitions and aggregate counts can be created/imported with the existing manual source tools below, subject to owner/permission checks. They are user-declared, not CRM integration or verified quality. Spend histories must already exist through a supported validated source path. This package does not seed a real account or fetch one for you.
3. Select **Maximize declared leads** and enter a positive 30-day budget in account currency. Submit. Every entity/day in the selected source population needs matched spend/count observations; failed source/quality/fit gates return a reason instead of a partial recommendation.
4. CURRENT is modeled no-change, with observed imported count separately labeled. It can violate the new requested cap. OPTIMIZED includes a same-spend mix baseline; do not confuse this with historical actual response or causal lift. Observed spend-support and constraint flags remain visible.
5. Edit custom campaign budgets, scrolling the table sideways on a phone. Submit **Evaluate custom on frozen curves**. Exact archived campaign keys and constraints apply, with spend/count/delta/remaining-cap output. Editing again clears the old evaluation. No refit, metric query, optimization archive write or campaign change occurs. An infeasible custom allocation is not an accepted recommendation. Unequal-spend difference is not pure reallocation.
6. Replay the frozen optimized run or use the owner archive. Current data/configuration/clock changes do not replace archived scope. Checksums detect accidental mutation, not privileged database tampering. Custom evaluations are read-only and not persisted as new runs.

## Reproducible synthetic success, not business evidence

GuidedLeadScenarioTest creates one local account and two campaigns, creates an immutable definition through the API, imports 60 campaign/day counts through the API and solves. Spend rows are deliberate local fixtures with required normalization markers, not a live provider-response or spend-semantics verification. Generic purchase columns contain unrelated large values and do not become leads. Custom response is independently recomputed from archived formula parameters. Owner/scope/tamper/domain/constraint/stale-state regressions and frozen replay after current-source deletion are covered.

Synthetic example: historical imported count 2716; modeled no-change 2977.74 at 10500 spend; optimized 2667.26 at 8000; same-spend baseline 2654.01. Custom 7000 spend leaves 1000 of the cap, with response depending on its campaign mix. These are test outputs, not an owner budget, forecast guarantee or actionable real-account recommendation. Equal daily spend is assumed for period scaling. Declared event credits are not verified lead quality, qualified leads, unique people, sales, revenue or profit.

## Remaining original scope and blocked portfolio work

- **Cross-provider portfolio allocation is not currently available.** Original section 40 permits descriptive platform-reported credits without a unique-person claim; this conservative implementation limit is not a claim such sums are mathematically impossible.
- **Meta+Snap purchase-credit branch remains blocked.** Exact returned Meta purchase-window action_values denomination/account-versus-event currency was not established from primary evidence. Secondary corroboration is not adopted as a source contract. Snap unit/date/watermark dimensions and precise sources are preserved in REPORTED_PURCHASE_CREDIT_CONTRACT_HOLD.md. No new marker, declaration or UI overrides this gap.
- Original-provider full field/unit/attribution/omission/finality contracts, API lifecycle, permissions and live responses remain unverified. Snapchat hierarchy remains incomplete. More provider cards do not solve these facts.
- Canonical placement and remaining provider creative/audience mappings, separate-import equivalence and numeric storage/accounting policy remain incomplete.
- Broader objective/data/campaign routing and any additional model/solver classes need justified contracts and independent validation. Engineering sensitivity/stress/robust ranges are not calibrated probability or forecast intervals.
- Full integrated analyst/report coverage, general numerical Q&A, trained attribution, calibrated saturation/uncertainty and identity/deduplication remain incomplete. Automatic CRM/server-side feeds and person identity are separate future work.

ORIGINAL_OUTCOME_RECONCILIATION.md maps original sections to bounded implementation and remaining work. PROVIDER_IMPLEMENTATION_MATRIX.md describes code scope, not live connectivity. Older milestone counts and claims describe their historical checkpoint only. No push, deployment, historical repair, real-data/account/campaign change or spending was performed. Further feature work pauses at this source boundary pending the next original-scope priority review.
