# First-party conversion event ledger

## Scope

`GET/POST /api/v1/ad-accounts/{account}/conversion-definitions/{definition}/events` stores and inspects explicitly submitted event evidence under one owned account and immutable conversion definition. Write permission requires manage-accounts or Owner/Admin. Definition counting_method must be events. This is a manual API boundary, not an automatically connected CRM, public collection endpoint or provider Conversions API.

POST body contains only an events array of 1-500 objects. Each object has exactly:

- event_key: opaque source event key, 1-128 ASCII letters/digits/underscore/dot/colon/hyphen. Use random non-personal keys, never email, phone, customer identity or secrets.
- entity_level: campaign, ad_set or ad. entity_id must belong to the exact account/provider parent chain.
- occurred_at: valid second-resolution ISO8601 with explicit Z or offset, epoch through now. Offsets are bounded to 14 hours. Equivalent instants normalize to UTC.
- value: nonnegative decimal with at most four decimal places and maximum 9,999,999,999, stored at four places. This is user-declared event value, not verified receipts, profit or refunds.
- currency: exact uppercase account currency. No conversion is performed.

Every accepted row represents one source-declared conversion event. It is not a person. Definition semantics retain kind, source system, counting method, attribution model and windows. No source truth or qualified-lead quality is independently verified.

## Idempotency and integrity

Key scope is the conversion definition plus exact event_key. An exact canonical-content retry returns replayed, without adding another row. UTC-normalized instants and fixed four-decimal values make equivalent textual representations replayable. Changed content under an existing key returns409 and rejects the entire batch, including otherwise-new rows. Duplicate keys within one batch return422. Definitions are locked during checking/inserting to serialize competing imports on PostgreSQL; a database unique constraint independently protects key scope.

The endpoint offers no correction/update/delete method. A mistaken event is not silently replaced. An authorized correction/reversal workflow remains future work. A new key or definition is a new declared event, not automatic correction or deduplication. A payload hash assists exact retry comparison; it is not signed source evidence or protection from privileged database editing.

GET uses descending ID cursor pagination, limit1-100 (default50), optional before_id. Its rows remain owner-scoped; pagination order is acceptance ID, not event occurrence order. Read and write permission are checked separately. Responses label source=user_imported_event and provider_verified=false.

## Explicit separations

The ledger does not merge into TypedConversionDaily, PerformanceMetric, optimization inputs or attribution journeys. Adding those representations would require an explicit non-overlapping population and provenance contract. No cross-source/account/definition deduplication, customer identity, touchpoint ingestion, trained attribution, CRM feed, refund reconciliation, deletion/retention UI or live provider verification is implemented. No external accounts, campaigns or budgets are changed. There is no event-ledger UI in this increment.

Local tests cover authenticated ownership/permissions, parent scope, exact and normalized retry, atomic conflicting batch, strict fields/values/times, cursor isolation and separate definition key scopes. These are synthetic local PostgreSQL tests, not a production concurrency/load or live data test.

## Explicit stored click evidence and attribution

A separate POST `/api/v1/ad-accounts/{account}/conversion-definitions/{definition}/events/{event}/journey` accepts only a touches array (1-100). Each touch has exactly event_id (opaque non-personal key), channel (declared lowercase slug), at (valid explicit ISO8601 timestamp). Authenticated owner scope and write permission match event imports. The source declares that these are paid clicks belonging to this conversion; no identity is inferred. Strictly increasing click timestamps, unique click IDs per journey and no clicks after conversion are required. Every click must fall inside the definition click_window_days*86400 seconds before the event, inclusive. This is a fixed-duration window, not provider-specific calendar-day attribution. Input is rejected, never silently clipped. View-window metadata does not turn clicks into views. Source channel labels are declarations, not live provider verification.

One immutable journey is stored per event, normalized to UTC with hash, acceptance time and event foreign key. Exact normalized retry replays; differing content conflicts. The event row lock and unique foreign key serialize acceptance. No update/correction API is provided. A click can appear in different conversion journeys; this allocates each conversion, it does not assert unique clicks or people across conversions.

POST `.../conversion-definitions/{definition}/stored-attribution` accepts event_ids (1-500 distinct stored IDs), model, optional compare_models and half_life_days. It is a read calculation under authenticated owner scope, including for a viewer. Every requested event must belong to this definition; all must have stored journeys. A missing journey returns incomplete_journey_evidence with no allocation, not a partial total. The source hashes are rechecked before calculation; mismatches return409. Hashes detect accidental content changes, not malicious database administrators changing data and hash together.

The five existing attribution rules run over exactly the selected event values and stored clicks, with the same conservation and ordered-time contracts. Data-driven attribution still abstains. Responses carry the selected definition semantics, per-event/journey hashes and acceptance times, and a deterministic canonical input hash. Source-declared attribution_model and selected analysis rule are deliberately separate. No rule is claimed as true attribution, causal lift or trained data-driven allocation. No persistent model-result archive or UI is included in this increment; re-execution can change when engine code changes. Explicit stored-journey input is now supported, but no automatic event feed, identity matching, optimization integration or cross-source deduplication has been added.

Timestamp regression: event/journey models write offsets explicitly, including acceptance time, so PostgreSQL sessions in Africa/Cairo do not reinterpret UTC dates as local wall time. A local regression checks exact stored UTC instants under the non-UTC database session used for verification. The initial ledger commit lacked this explicit date format; use the subsequent stored-evidence increment rather than that intermediate commit alone.

## Manual evidence UI

Evidence & Diagnostics now has a separate stored-event workflow: explicit owned account/definition IDs, empty JSON import forms, cursor-paged event list, immutable click attachment, explicit stored-event selection and rule/5-rule comparison. No source records or synthetic examples preload. Scope changes clear private form/result state; stale in-flight responses cannot replace the new scope or edited analysis selection. Tables scroll inside their card on phones. Local synthetic desktop/mobile verification checked the workflow and source labels. Seven front-end tests cover empty state, input guards, stale-scope/analysis replies, pagination, retry accounting and conflict/abstention states. No live provider connectivity is implied.
