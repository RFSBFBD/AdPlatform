# Original-provider synchronous fetch boundary

Local development after consolidated package d871b22. No delivered file/link is changed and no new package is created for this block. All providers remain code/fixture-only and not approved for real budgets.

## Confirmed source failures addressed

Google paged search previously had no token-cycle/page cap, accepted missing/malformed result shapes as empty, discarded secondary ad hierarchy errors, and retained only campaign raw pages. TikTok ad/group errors were logged then ignored, page counts were cast/defaulted and traversal stopped on an empty list even when more pages were declared. Snapchat absent nested timeseries wrappers became empty loops, so a malformed success envelope could advance the sync as if complete.

These are source-code defects, not claims any real API response or customer database exhibited them. Existing history is unchanged. Earlier strict numeric parsing cannot recover records a partial fetch silently omitted.

## New local contract

Google search requires an explicit results list, object rows, absent terminal nextPageToken or a bounded nonempty string token, no token repetitions and at most100pages per edge. SearchStream list envelopes are no longer accepted by the search endpoint. Empty intermediate results with a token continue. Present null/empty/malformed token refuses. Campaign, ad-group/ad and insight traversals use this check; HTTP/redirect/error/malformed hierarchy results fail instead of returning partial hierarchy. IDs/duplicate ads/campaigns/metric entity-day and conflicting/missing fetched parents reject. Raw successful hierarchy results retain both campaign and ad-group/ad page lists. The ad_group_ad query is still not a complete ad-group-without-ads inventory, so successful local traversal does not assert that inventory.

TikTok's implemented adgroup/get, ad/get, campaign/get and report/integrated/get edges share a bounded local reader. Each response needs code0, explicit data.list and integer total_page from1to100, unchanged across pages; an optional returned page must match the requested page. Empty intermediate lists continue through declared page count. Missing, zero, string, changing or excessive page count refuses. Raw successful pages for all hierarchy edges are retained. Duplicate IDs/metric entity-day and unknown/conflicting parent references reject rather than silently skipping or attaching one ad under several parents. A local100000combined-row cap per edge applies.

Snapchat account/day stats require explicit successful timeseries_stats and exactly one explicit account subrequest with timeseries_stat.timeseries. Explicit empty inner daily list remains a local empty-report representation; absent wrappers are not that representation. Failed/malformed subrequests, any unimplemented paging, duplicate day or day outside its requested chunk refuses. Date input is exact and range is bounded to366days. Existing account-local chunking and field/divisor/window selections stay unchanged. This does not prove daily timestamp offsets or returned account dimensions and does not fill missing days.

Discovery now rejects malformed/duplicate explicit rosters. Unhandled TikTok/Snapchat discovery paging refuses instead of ignoring it. Google resource names must be explicit customer identifiers; individual detail failures still yield unknown metadata, which cannot create a valid connection. No complete roster is inferred from absent fields. HTTP redirect following is disabled on these request builders. Shape lists have a local10000-row-per-page cap. Caps are engineering limits, not vendor quotas.

## Preservation and success semantics

Successful fetches include fetch_status=complete_local_traversal_not_inventory_verified. It is a local return value, not a verified provider snapshot or replacement for existing normalization/account evidence. Shared sync still owns entity resolution and transactional metric writes. The complete sync is not atomic: raw/hierarchy records can precede an insight failure. Later-page/chunk failure writes no newly fetched metrics and does not advance last_synced_at, but it does not roll back earlier hierarchy observations. No old metric/account/archive is repaired, deleted, relabeled, resealed or automatically reimported. No campaign mutation endpoints are introduced.

## Conservative compatibility and remaining evidence

Google protobuf omitted-empty results may be legitimate, and TikTok zero/omitted total_page may have special meaning; this block does not guess either interpretation. Those forms refuse until endpoint-specific evidence is obtained. Discovery paging is refused, not newly implemented without a grounded contract. Configured versions, selected Google pageSize/query compatibility, TikTok reporting-version migration, Snapchat nested wrappers/date offsets/account identity, source permissions and all metric/attribution/unit semantics remain live/contract verification dependencies. Google groups without ads, Snapchat campaign hierarchy and other missing report inventory remain incomplete. Meta retains its separately documented existing cursor guards and non-atomic limitations. No source-fetch success is a real-budget approval.

## Verification

Full synthetic backend514tests/4104assertions passed, including the final group-parent-conflict/per-page-row-cap refinement. Added tests exercise original-provider malformed discovery, Google token cycles/caps, TikTok bad/changing page declarations, empty intermediary pages, duplicate reports, orphan hierarchy, failed child queries, Snapchat wrappers/chunk scope/paging and later-chunk failure. Shared-sync regressions retain earlier stored metrics and unchanged last-success on later Google/TikTok page failures; Snapchat later-chunk failure inserts no early metrics. Existing Meta bounded paging tests also run. No frontend changed, so no new visual result is claimed. No live API/account, historical repair, deployment, push or spending.
