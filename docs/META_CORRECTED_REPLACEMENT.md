# Corrected source replacement, 23 November 2026

## Do not carry old Meta totals forward as verified

Both earlier source checkpoints572a8d8 andc70d5ce summed grouped purchase aliases, a pixel purchase component and leads into generic conversions. They summed multiple purchase-value aliases too. Absent action arrays became zero. Meta conversion counts/values, CPA, CVR, ROAS, AOV and any diagnostics, forecasts, scenarios, rankings or budget recommendations based on those operands can therefore be misleading. The action-sum defect does not directly change spend, impressions, clicks, reach or frequency. It does not establish that those other fields or the overall dataset are complete or correct.

Neither earlier checkpoint contains the later nested Meta ad-set/ad paging fix. Their child inventory can be partial. c70d5ce adds canonical creative identity and stricter ingestion compared with572a8d8 but does not correct action semantics. Keep both old files unchanged for traceability, not as corrected versions. This replacement is a development source checkpoint, not production approval or whole-spec completion.

## New ingestion

Meta now reads exactly offsite_conversion.fb_pixel_purchase count and value. It does not add omni_purchase, purchase or lead. This is website pixel-purchase data, not every destination's purchase total. Missing selected action types return unknown/null, not zero. A numeric explicit0 remains0. Duplicate/invalid selected values fail. Required unknown outcomes cause the shared metric batch to fail without replacing earlier metrics. This may block omitted-action/no-purchase reports. No automatic omission-to-zero interpretation, app/offline/lead substitution or campaign-objective guessing occurs.

Definitions are persisted as meta-pixel-purchase-1. Attribution/date defaults remain provider-default/unverified. Provider-defined purchase value is not verified sales, accounting revenue, causal effect or unique people. Current live access, configured Graph version and attribution comparability still need separately authorized verification.

## Analysis boundary

MetaDefinitionTransitionGuard requires all Meta rows in a guarded scope to have the exact version and pixel count/value keys, and identical complete supplied definition objects. Legacy-only, unknown, mixed and differing new definitions fail. This is a version/consistency gate, not proof the provider or advertiser reported true sales. No test-data label can verify real data. Other providers' semantics are not certified by this guard. Cross-platform attribution remains unverified.

Guarded read paths: overview, campaign list/details, Looker data export, daily KPIs, observed series, forecasts, anomalies, diagnostics, campaign health, creative fatigue/matrix, audience entity/day matrix, assumed/fitted portfolio optimizers and entity optimizers. Conservative mixed-scope guards can withhold otherwise valid spend information alongside unsafe outcome information. Raw audience reach/frequency inspection and structural/object identity audits do not use conversion outcomes and remain available. The data-quality engineering score alone does not certify outcome definitions.

Explicit typed lead/qualified-lead optimization is separate: it uses the user-declared typed source rather than generic Meta outcomes, so the generic outcome gate is not substituted for its existing typed-definition checks. It still is not provider verified.

Archived source rows are checked too. Unsafe legacy Meta results remain stored byte-for-byte, but API list/show withhold decision operands, replay/explanation/comparison refuse and HTML decision-report export returns409. Archive source snapshots with missing source_rows are unverified. Existing previously downloaded reports/screenshots cannot be revoked. No history repair, backfill, deletion, automatic resync or resealing is performed. Applying this source does not correct a database already filled by older versions. Verified reimport planning is separate work and must not be improvised against live data.

Synthetic regression fixtures now explicitly declare their intended pixel-purchase test definition where they exercise valid Meta model paths. Separate legacy-only and mixed-version fixtures verify refusal and unchanged metrics/archives. This avoids making a test fixture's lack of metadata an accidental permission to analyze real legacy rows.

## Other included later development

The replacement also contains strict canonical hierarchy/run/raw-payload lineage, read-only structural audit, exact creative/audience object observations, manual first-party event/click ledger and stored attribution, bounded nested Meta hierarchy traversal and readable evidence UI labels. These do not create historical creative/audience performance attribution, people matching, audience overlap or complete all-provider canonical mappings. Canonical placement identities, broad provider object mappings, automatic first-party feeds, calibrated uncertainty, general LP/NLP/QP routing and the full integrated AI analyst remain incomplete. Provider fixtures are not live connections. Nested paging is bounded and not an atomic inventory snapshot.

References inspected: https://developers.facebook.com/docs/marketing-api/reference/ads-action-stats/ , https://developers.facebook.com/docs/marketing-api/insights/action-breakdowns/ , https://developers.facebook.com/docs/graph-api/results/ . More detail: META_PURCHASE_DEFINITION.md, META_HIERARCHY_PAGINATION.md, CANONICAL_AUDIENCE_IDENTITY.md and MATH_SPEC_COVERAGE.md. Historical milestone documents describe their own dated scope, not the replacement's safety status.

No deployment, push, live provider request, customer data change, campaign mutation or spending is included. Installed dependencies, secrets, local DBs, logs and internal notes are excluded from source delivery. The parent retains owner-only delivery control.
