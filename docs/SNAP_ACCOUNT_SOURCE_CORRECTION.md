# Bare Snapchat account stats correction

24 November 2026, after delivered a252250. This correction is included in a separate source-only replacement; earlier artifacts remain unchanged. No live execution is included.

## Material delivered-code consequence

The implemented SnapchatAdsAdapter used bare `/adaccounts/{id}/stats` without breakdown but requested/parsed impressions, swipes, conversion_purchases and conversion_purchases_value. Official measurement documentation states that spend is the only metric available for this ad-account entity. Its campaign/breakdown stats are separate source paths, not implemented by this correction.

The old shared importer already rejected missing required delivery/outcome fields, so a documented spend-only nonempty reply would fail, not fill zeros. But an unsupported mocked/imported payload containing all fields could pass numeric checks and receive a source-outcomes-1 contract. Single-provider descriptions/KPIs/forecasts/diagnostics and archived decisions could pass existing guards, even though the cross-provider optimizer already abstained. The formerly passing Snapchat conversion fixtures were not valid vendor-contract success evidence. This is a source-scope defect, not evidence of any real account corruption.

## Narrow fail-closed behavior

- Bare account stats now requests only spend and omit_empty=false, keeping existing chunk/error/numeric validation. It returns `spend_inspection` and raw response separately, no canonical metrics, with `canonical_import_status=unsupported_source_scope`.
- Unsupported extra delivery/purchase fields in a reply never become canonical fields. No replacement campaign hierarchy, aggregation or source-equivalence marker is invented.
- Shared sync preserves raw inspection payload, then refuses Snapchat canonical metric import even if an adapter-like payload claims success or includes all numeric fields. It does not write metric rows or advance last_synced_at, including explicit empty replies. This is not a successful full sync.
- Historical Snapchat canonical rows, including normalized/source-marked rows, are withheld by existing read-time analytical/typed-spend/archive guards. Outcome-only guards also reject them; no old row/checksum is edited or resealed. Existing completed-sync audit timestamps remain historical events, not eligibility.
- Coverage/suggestions no longer imply bare account purchases or fixed48h conversion finality. Legacy age-cutoff helper is retained only as an engineering filter, never a source/finality certificate.
- Separate raw spend inspection remains available in the adapter return and raw audit record. It is not a new dashboard, finalized spend certificate or real-budget approval. Existing canonical schema requires all delivery/outcome columns; inventing values to admit spend is forbidden.

This intentionally withholds all historical Snap canonical analysis, including spend/delivery totals, because current storage does not establish a valid separated source scope. Raw source spend is not deleted. A future supported spend-only canonical schema or campaign conversion adapter is outside this correction.

## Meta assessment, no runtime change

The current Meta importer does not send action_report_time or use_unified_attribution_setting. It records `date_basis=provider_default_unverified` and unverified attribution; objective preflight explicitly records unverified report-date basis. No claim of explicit reporting-parameter control was found. Therefore there is no new date-policy eligibility to remove. Same-source analytical capability remains subject to the broad already-delivered unverified-source/real-budget warning, not newly certified by this assessment.

Primary dated notice says those parameters are disregarded across versions from June10,2025; best practices says off-Meta web purchases use conversion date within mixed reporting. Old reference enums are not evidence the caller controls effective policy. Both proposed cross-provider branches remain stopped. The exact Meta Insights spend/unit gap and separate action_values monetary hold remain unresolved, not reopened here.

## Evidence and verification

Primary source: https://developers.snap.com/marketing-api/Ads-API/measurement , bare ad-account spend-only section, conversion versus nonconversion watermarks, and account-local dates.
Meta: https://developers.facebook.com/docs/marketing-api/out-of-cycle-changes/occ-2025/ and https://developers.facebook.com/docs/marketing-api/insights/best-practices/ .

Regressions verify documented spend-only parsing, ignoring unsupported extra fields, raw preservation, failed canonical sync with unchanged success timestamp, fake normalized-import non-bypass, historical read-time withholding without data edits, typed-source/archive refusal, and revised coverage/finality expectations. All prior tests are retained with unsupported success expectations corrected. No live API/account/campaign, deployment, historical repair or purchase is part of verification.

## Replacement use

Install as source using OPERATING_GUIDE.md, then run migrations/tests on a separate local database. No new schema is required by this correction. Do not repair or reseal older Snapchat rows to bypass the source guard. A failed Snap canonical sync is expected even when a raw spend response succeeds; consult its raw audit record for source-spend inspection, not dashboard metrics. Guided declared-lead scenarios remain available only for otherwise supported source histories; Snap spend is not an exception. No automatic resync, historical deletion or production rollout is part of this replacement.
