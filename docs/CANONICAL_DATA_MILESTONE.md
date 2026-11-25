# Strict canonical metric hierarchy, milestone 1

## Dependency and user value

Canonical normalization precedes KPIs, fitting, optimization and AI in the original specification. An unresolved external campaign/ad-set/ad must never silently become an account-level report. A reused external child ID must not choose an arbitrary parent. These are data architecture correctness requirements, not optional reporting enhancements.

The shared AdDataSyncEngine now resolves every metric in the complete batch before writing any metrics, using CanonicalMetricEntityResolver. Every supplied ID must resolve uniquely within the connected account, provider and explicitly supplied parents. A unique child may supply its actual missing parents. A missing/blank/ambiguous/conflicting identifier fails the batch rather than changing its hierarchy level. If a metric supplies account/provider/currency or entity_level, those must agree with the canonical account and resolved hierarchy. Actual account-level rows have no supplied entity identifiers, or explicit null identifiers, and remain account-level.

Distinct external rows that resolve to the same canonical entity/day are rejected, so short versus fully qualified forms cannot overwrite each other. Scalar/date validation also runs before metric writes. The metric write loop is transactional, so a write failure cannot leave a partial batch. Raw payload and hierarchy sync records are retained even when metric validation fails; the whole sync process is not claimed atomic. Failure status remains on SyncRun, and last_synced_at is only advanced after successful metric writing.

Each newly stored metric retains source external IDs, strict-parent-1 resolution version, sync-run ID, raw insight payload ID, source endpoint, account reporting timezone and retrieval time. This is local normalization lineage, not proof a provider supplied semantically correct or complete data. Only the shared sync engine is covered; separate report import workflows need their own equivalent contracts.

## Verification

Synthetic fixtures check reused child IDs disambiguated by parent, unknown supplied IDs with a preceding valid row (zero metric writes), ambiguous child-only lookups, conflicting parents, cross-account IDs, declared level/currency/provider/account mismatches, canonical duplicate aliases, parent inference and genuine account-level reports. Existing provider and sync tests pass. No live authorization, account, campaign or provider request was exercised.

## Remaining dependencies

This milestone does not create canonical creative, audience or placement identities, link reusable provider objects to them, reconcile identity across providers, or verify every provider hierarchy contract. It does not repair old rows imported before strict resolution. A separately authorized migration/audit is required before changing historical data. It does not prove complete campaign populations, attribution comparability, tracking coverage or real source correctness. Account-level-only adapters still cannot support entity-level OR. Provider approvals and current API field/window semantics remain external validation requirements. Frozen source checkpoint572a8d8 predates this continuation and remains unchanged.

## Read-only historical integrity audit

GET `/api/v1/ad-accounts/{id}/canonical-integrity?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD` is authenticated owner-scoped, read-only and bounded to366 date labels and30000 source rows. Exceeding the row cap returns local_limit without a partial pass. Empty scope returns insufficient_data, not a zero/pass. PostgreSQL reads use a repeatable-read snapshot when called outside an existing transaction.

The audit checks stored account/provider/currency and complete parent linkage, duplicate canonical entity/day (including inconsistent provider aliases), and declared level. Strict-parent-1 lineage additionally requires an owned completed sync run, owned matching raw insight payload/endpoint, explicit retrieval timestamp and source external IDs agreeing with stored canonical parents and deepest level. References are batch-loaded, not one lookup per metric. Issue counts cover all audited rows; samples are capped at50 and contain only local metric ID/date/code/severity. It does not return raw customer or provider payloads.

Possible outputs distinguish integrity_issues, structurally_consistent_unverified_lineage and structurally_consistent_recorded_lineage. None is called provider verified, a complete data-quality pass or permission to optimize. Legacy/seed/import rows stay unverified even when structurally valid. Multiple hierarchy levels are reported separately and are never pooled by this audit. Tests verify owner/date guards, empty windows, unchanged legacy rows, foreign parent and duplicate scope, owned successful run/payload, and source-ID mismatch detection. UI and remediation workflow are not part of this endpoint; no historical record changes occur.

A subsequent bounded creative identity block is documented in CANONICAL_CREATIVE_IDENTITY.md. It supersedes the missing-creative-table limitation only for exact provider creative objects/observations, initially mapped by Meta. Audience/placement identities and historically effective creative/day attribution remain incomplete.
