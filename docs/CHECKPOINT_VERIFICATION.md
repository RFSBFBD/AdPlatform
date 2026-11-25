# Interim source checkpoint, 23 November 2026

This package is usable local source, not completion or cancellation of the original specification. Work continues beyond this checkpoint. Read OPERATING_GUIDE.md first, MATH_SPEC_COVERAGE.md for scoped implementation evidence and PROVIDER_IMPLEMENTATION_MATRIX.md for provider-specific boundaries.

## What was checked

Committed sources were exported into an empty directory, without local environment files, installed dependencies, database dumps or Git history. From that export:

- Composer manifest validation, locked dependency install including package-discovery scripts, and platform-requirements checks passed using PHP 8.3.17. A second PHP 8.6 runtime also passed the backend suite; it is not the deployment requirement.
- A fresh local synthetic PostgreSQL database accepted all migrations. Analytics route registration was checked.
- Backend: 446 tests and 3483 assertions passed, including local backup/restore and optional solver contract tests.
- Frontend: clean npm dependency install, zero reported audit vulnerabilities at check time, 121 tests passed, production bundle built. Runtime: Node 22.23.5 and npm 10.9.9. package.json declares npm 11.12.1, which was not used for this check.
- PHP application, routes, database, config and test files were syntax checked.
- Desktop/mobile application and generated-report layouts were visually inspected using synthetic data during development. No claim is made that every browser or viewport was tested.

The standalone FrankenPHP launcher needs its php-cli subcommand and therefore cannot directly satisfy Composer's child @php calls in this test setup. A conventional PHP 8.3 CLI successfully ran the normal Composer scripts. This launcher-specific issue is not hidden as a successful plain install.

Tests establish bounded implemented behavior, not full mathematical correctness, causal lift, provider authorization or future performance. Re-run them on the target runtime. The optional discrete route needs the isolated pinned Python/NumPy/SciPy environment described in EXTERNAL_SOLVER.md. Runtime binaries and dependencies are not bundled. Container/deployment configuration was not built or deployed.

## Remaining local implementation

- Canonical audience, creative and placement identities and platform-specific hierarchy completeness.
- First-party event ingestion, identity resolution and durable idempotency/deduplication.
- Stronger attribution comparability and trained data-driven attribution.
- A defined, validated saturation estimand and calibrated uncertainty, not a guessed threshold.
- General LP/NLP/QP routing beyond the current certified concave and finite-menu routes.
- Full integrated analyst workflow, free-form numerical Q&A and all requested visual/report coverage.

## External validation still required

No live provider account or real campaign was used. Fixture mapping, manual imports and provider-verified data are distinct evidence classes. Provider approvals, eligibility, OAuth credentials, rate/volume behavior, API semantics and production operations require separate authorized verification. Current known contract gates remain documented in the provider matrix, including Microsoft timezone/download-host mapping, Amazon report-window/download-host rules, Outbrain timezone/DST and Walmart partner signing/token access.

No push, deployment, campaign/account mutation, paid service or purchase is part of this checkpoint. Private owner-only package access does not grant team sharing. Source is delivered without secrets; configure credentials locally and never put them in frontend code or source control.
