# Canonical audience-object references

A provider audience object is identified by exact connected account, provider and external audience ID. This is not a set of people stored by AdPlatform. No email, phone, member list or inferred identity is requested. Names never create equivalence across objects, accounts or platforms.

A targeting observation belongs to one owned ad set and sync run, with raw hierarchy payload, UTC observed time and canonical reference checksum. Included and excluded roles are stored separately. Explicit empty included/excluded lists are recorded as observed-empty, while a missing field is unknown. An object appearing in both roles is preserved, not silently reconciled. This is source-declared configuration, not a decision about delivery semantics, audience membership, overlap, reach or conversions.

Each ad-set/run observation is immutable through the recorder. Canonical sort makes equivalent list order retries replay; changed content conflicts. Duplicate IDs within one role, unknown extra fields, malformed lists and foreign ad-set/run/payload scope reject the observation. References can be reused by multiple ad sets/runs without creating another audience identity. Another account/provider remains a different identity. Changes across syncs retain history. Missing observations never imply removal, and failed sync observations stay labeled failed. Existing observations are not rewritten when a display name changes.

Initial provider mapping is only Meta targeting custom_audiences (included) and excluded_custom_audiences (excluded) from the existing ad-set hierarchy read. Other targeting properties, flexible rules, lookalikes, demographic audiences and other provider audience types are not mapped by this feature. No extra audience-member API request is performed. Nested hierarchy pagination remains incomplete, so inventory completeness is not claimed.

Official references inspected 23 November 2026:

- https://developers.facebook.com/docs/marketing-api/reference/targeting/ documents separate custom_audiences and excluded_custom_audiences fields.
- https://developers.facebook.com/docs/marketing-api/reference/custom-audience/ describes custom audience objects. Its member-add/remove operations are outside this implementation and are not called.

Current documentation/version availability is not proof the configured adapter version, live permissions or account eligibility works. HTTP fixtures establish request/mapping behavior, not live connectivity. No API-version upgrade, authorization or real ad account was used.

## Private inspection

GET `/api/v1/ad-accounts/{account}/canonical-audiences` lists exact identities, default 50/max 100, descending before_id cursor. GET `.../audience-targeting-observations` lists ad-set/run snapshots with separate roles, field-observed flags, failed/completed sync labels and integrity checks. It has the same page limits and a 200000-reference local safety cap. Exceeding the cap returns no partial result. Reference checks validate owned identity/run/raw-payload scope and canonical checksum. Corrupt snapshots withhold descriptors/reference details. No raw provider payload is returned.

Evidence & Diagnostics has an explicit load and cursor-paged reference table using the account field in canonical inspection. Inputs start empty; it makes no page-load requests. Account switches clear results and invalidate old replies. Phone tables scroll within the card. Existing audience matrix stays an exact entity/day proxy, not a canonical audience performance table.

## Verification and missing dependencies

Synthetic tests cover included/excluded separation, same-object opposite roles, ordering-insensitive retry, explicit empty versus unknown, conflicts/malformed fields, foreign scope, UTC persistence, private inspection and altered-label integrity mismatch. Meta fixture mapping also runs through the shared sync. Full backend 471 tests/3722 assertions and frontend 134 tests/build passed including the readable-status label adjustment and malformed scalar-reference regression.

Still missing: all-provider audience mappings, complete inventory pagination, historically effective targeting intervals, exclusion/flexible-rule delivery semantics, placement identities and metric-grain bridges. No audience/member overlap estimator, person resolution, calibrated saturation or causal inference is added. This block remains separate from event ledger, aggregate optimizer and provider-reported daily metrics. No campaign, account, audience membership or targeting mutation occurs. Stable c70d5ce source milestone and earlier572a8d8 delivered checkpoint are unchanged by this later development.

A later backend block traverses Meta nested hierarchy next links with strict URL and bounded failure rules, documented in META_HIERARCHY_PAGINATION.md. It improves read coverage but does not establish an atomic/full inventory, historically effective targeting or provider authorization.
