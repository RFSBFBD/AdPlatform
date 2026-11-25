# Canonical provider creative identity milestone

## Identity and observation model

A CanonicalCreative is identified only by connected account, provider and explicit external creative ID. A display name, content, URL or ad ID never creates equivalence. The same external ID in another account/provider is a different identity. No cross-provider or content deduplication is claimed.

CreativeObservation binds that identity to an ad at a particular sync run, with hierarchy raw-payload reference, observed timestamp, nullable display name/object type and descriptor checksum. Exact same ad/run evidence replays; conflicting evidence is rejected. Account row locking serializes identity creation and a unique key protects identity scope. Observations retain changes across runs and creative replacements rather than overwriting an ad's history. Explicit-offset date writes preserve UTC instants under non-UTC PostgreSQL sessions.

Unknown/missing creative ID stays unknown. There is no invented IMAGE type, identity derived from URL/name, automatic removal event or historical metric backfill. Descriptors are untrusted display data. The hash detects accidental descriptor change, not database-admin tampering.

## Ingestion and provider boundary

The shared sync engine accepts an optional canonical_creative object on normalized ad records: external_creative_id, nullable name and nullable object_type. The recorder checks ad/account/provider/run/raw-payload scope. Only explicit identity is stored. The initial integrated adapter is Meta: its hierarchy read now requests ads' creative{id,name,object_type} and maps the returned ID. No other provider mapping is implied. Existing nested hierarchy pagination limitations still apply; an observed set is not complete inventory.

Official field/object references inspected23November2026:

- https://developers.facebook.com/docs/marketing-api/reference/adgroup/ supports the ad creative relation.
- https://developers.facebook.com/docs/marketing-api/reference/adcreative/ documents creative ID, name and object_type; object_type can be missing or unavailable.
- https://developers.facebook.com/docs/graph-api/reference/adaccount/adcreatives/ describes reusable creatives within an ad account.

These current reference pages may display newer versions than the configured adapter. This work did not upgrade the configured Graph API version, verify its lifecycle/permissions or run a live request. HTTP fixtures prove request/mapping behavior only. Live version migration and full hierarchy pagination remain external/implementation dependencies before claiming production support.

## Inspection, not historical attribution

Owner-scoped GET endpoints:

- `/api/v1/ad-accounts/{account}/canonical-creatives`
- `/api/v1/ad-accounts/{account}/canonical-creatives/{creative}/observations`

Both support descending before_id cursor and limit1-100, default50. Identity list counts are recorded observations/distinct referenced ads, not serving coverage. Observation history checks owned ad/run/raw-payload chain and descriptor hash. Corrupt observations withhold descriptor/resource details and return mismatch. Failed sync observations stay labeled failed; they are not promoted to successful pulls.

Evidence & Diagnostics includes explicit account entry, paged identity/history inspection and the read-only historical canonical audit. No automatic API action occurs on page load. Account switches clear results, and stale replies cannot replace a new scope/date selection. Tables scroll within phone cards. The existing creative-fatigue matrix remains an ad proxy; this feature does not relabel it as canonical creative performance.

An observation at sync time does not establish which creative served on any previous daily metric date. No metrics are aggregated by creative, no reach is summed and no unique people are inferred. Historical creative-effective intervals, provider-specific asset versions and actual creative/day reports need separate verified contracts.

## Verification and continuation

Synthetic tests cover shared identity across ads, separation across accounts, exact retry/conflict, creative replacement history, foreign scope, explicit-offset timestamps, private API and failed/corrupt observations. A Meta HTTP fixture runs through the shared sync and proves explicit identity import while a missing creative stays unknown. Front-end tests cover empty/explicit scope, stale replies, pagination and unverified/failed states. Desktop and phone pixels were inspected against synthetic local data.

This milestone includes the prior strict metric-entity resolution, historical audit and manual event/journey ledger. It does not complete canonical audiences or placements, all provider creative mappings, cross-provider identity, identity-based attribution, trained attribution, saturation calibration, general solvers or the full AI analyst. No production deployment, real provider authorization, account/campaign mutation or spending occurred. Earlier owner-only checkpoint572a8d8 remains unchanged; later source should be packaged as a distinct verified milestone, not silently replace its link.
