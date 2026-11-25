# Meta exact pixel-purchase metric definition

## Defect and bounded fix

Previous ingestion added purchase, omni_purchase, offsite_conversion.fb_pixel_purchase and lead actions into one conversion count, and added multiple purchase-related action values. Those action types cannot be assumed disjoint. Leads are not purchases. Grouped actions and components must not be added as though they were different sales.

The adapter now extracts exactly offsite_conversion.fb_pixel_purchase from actions and action_values. It never falls back to omni_purchase, purchase or lead. This narrows new observations to Meta-reported website pixel purchases and their attributed values, not all purchases across every destination. These are provider-reported actions, not unique people, click-only conversions, causal lift, verified sales or accounting revenue. Values may be revised by the provider. Apps/offline/onsite purchases and lead goals need separate definitions; this feature does not select one automatically from a campaign objective.

A matching action with numeric finite nonnegative value0 is a measured zero. Missing actions, an empty array or an absent selected type return null, not an invented zero. Duplicate selected action types, malformed lists/descriptors and negative/nonfinite/non-numeric/bool selected values reject. The current shared canonical importer requires known conversion/count value operands, so such missing outcomes cause that entire metric batch to fail. It does not import only the convenient spend rows. Earlier stored metrics survive a failed retry. This conservative behavior can block no-conversion reports where Meta omits the selected action. Until omission-to-zero semantics are independently verified for a specific report contract, that is intentional.

Each newly mapped row has metric_definition.version=meta-pixel-purchase-1 plus exact action keys, unknown-missing rule, timezone and explicit provider-default/unverified attribution and date-basis labels. The shared sync persists that definition with the row's source/run/payload metadata. The request attribution/date parameters and Graph version are unchanged, so cross-platform attribution equivalence and verified window semantics are not claimed.

## Evidence

Official references inspected23November2026:

- https://developers.facebook.com/docs/marketing-api/reference/ads-action-stats/ documents offsite_conversion.fb_pixel_purchase as purchases, grouped omni_purchase, lead and other action types. It identifies offsite_conversion actions as pixel-originated.
- https://developers.facebook.com/docs/marketing-api/insights/action-breakdowns/ documents action_type and its implicit action breakdown. This is not evidence that every action type is disjoint.
- https://developers.facebook.com/docs/marketing-api/insights/parameters was inspected for report context. No attribution defaults or revenue-equivalence claim is derived from that page.

Full synthetic backend479tests/3769assertions passed. Tests cover aliases plus leads without summing, exact value extraction, missing versus explicit zero, duplicate/invalid selected actions, shared-sync definition persistence and failed retry preserving existing rows. Frontend unchanged from134tests/build and prior visual check. No new visual output. Initial verification after pause used the wrong local database port and failed connections; the entire suite passed after restarting the same synthetic DB on55432. No live account/API or customer data was used.

## Remaining risk and follow-on

Existing historical rows are not recomputed or relabeled. Rows without this definition may contain the old ambiguous action sums. Current analytical consumers do not yet enforce versioned metric-definition equivalence, so mixed old/new histories must not be described as a validated optimization basis. An explicit history audit/analysis gate for definition differences remains needed before production use, including legacy and other-provider definitions. No historical repair, configured revenue verification or cross-platform event deduplication is included. Frozen delivered/source checkpoints predate this fix and remain unchanged. This is ingestion correction, not completion of canonical Conversion/Revenue modeling or a guarantee every current OR output uses comparable outcomes.

Subsequent replacement boundary: META_CORRECTED_REPLACEMENT.md documents fail-closed legacy-only/mixed definition checks across analytical and archive paths. This supersedes the earlier statement that downstream checks are absent, without changing the no-history-repair caveat.
