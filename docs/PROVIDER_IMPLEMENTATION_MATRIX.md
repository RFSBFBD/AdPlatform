## Current Snapchat limitation, 24 November 2026

Snapchat canonical analytical support is unavailable. The implemented bare ad-account stats source supports spend inspection only, not impressions/swipes/purchase metrics. Canonical sync refuses without metric writes or last-success advancement; old canonical Snap analyses/archives are withheld. No campaign/breakdown replacement is supplied. This supersedes older Snapchat rows below. See SNAP_ACCOUNT_SOURCE_CORRECTION.md.

# Provider implementation matrix

As of 2026-11-23. Every row is CODE/FIXTURE ONLY. No live provider integration has been verified and no Egypt/account-specific eligibility has been established. See PROVIDER_SOURCES.md for inspected source URLs. User credentials/app approval are independent of implemented code.

| Provider | Implemented today | Not implemented / blocked |
|---|---|---|
| Meta | Auth, bounded nested hierarchy traversal, creative/audience references; exact pixel-purchase count/value with definition metadata, missing outcomes fail closed; reach/frequency | Live verification; historical metrics are gated, not repaired; complete breakdowns/attribution equivalence |
| Google | OAuth, developer-token integration, strict numeric inputs, conservative bounded search/hierarchy traversal | Live verification; every report surface not covered |
| TikTok | OAuth, strict numeric inputs and conservative bounded synchronous hierarchy/report traversal | Live verification and v2 reporting migration not certified |
| Snapchat | OAuth, strict account discovery and account/day purchase wrapper/chunk validation | Campaign hierarchy; live verification; complete timestamp/account-dimension semantics |
| Taboola | Client grant/regrant, admin-only account discovery/selection, account/day spend/click/impression/conversion-value reports, UI | Campaign hierarchy; full bounded-chunk historical sync; live verification |
| Pinterest | User OAuth/rotated refresh, bookmark discovery, account/day sync reports, advertiser microcurrency /1e6, explicit attribution, UI | Async long-history reports; hierarchy; live verification |
| Reddit | adsread OAuth/refresh, business/account discovery, safe next_url paging with POST body, account/day UTC purchase metrics; spend /1e6, value /100; UI | Campaign hierarchy/breakdowns; live verification; attribution comparability |
| Spotify | OAuth/refresh, exact business/account wrappers, account/day UTC reports, billing-currency spend, token-only paging, suppression guards; UI | Hierarchy; partial delivery ingestion without revenue; live verification |
| LinkedIn | Configurable versioned OAuth/discovery, optional MDP refresh, regular ACCOUNT/day UTC reports; UI version control | Revenue mapping OFF by default until conversion definitions/values verified in deployment; hierarchy; live verification |
| Apple Ads | Current Platform API v1, admin client grant using encrypted pre-signed ES256 JWT, ACL account discovery/context; separate campaign/day delivery-report endpoint and UI | JWT signing/rotation; standard revenue sync deliberately disabled (installs are not sales); live verification |
| Outbrain | Provisioned token reader, marketers/budgets currency validation, paginated native periodic report parser | UI onboarding; account connection refuses unknown timezone; ET offset/DST unresolved, no pooled daily OR; no password login/refresh invented |
| Microsoft | Entra OAuth/refresh, GetUser/SearchAccounts, REST Bearer headers; persistent submit/poll/download/ZIP-CSV/import worker and UI request/status | Deployment must supply verified timezone enum-to-IANA map and signed-download host allowlist; durable queue required; missing configuration fails closed |
| Amazon | LWA OAuth/refresh, read-only regional profiles, persistent SP v3 submit/poll/GZIP_JSON/import worker; campaign/day aggregated once to account/day; purchases7d/sales7d with profile timezone and currency; setup/request/status UI | Deployment endDate semantics and exact download-host allowlist must be verified, empty defaults block; one app region; SB/SD/DSP not implemented; no live test; v3 deprecation June 30, 2027 |
| Walmart Connect Sponsored Search | Official onboarding and advertiserAttributesV2 snapshot contract researched and documented in docs/WALMART_BOUNDARY.md | No executable signer/token exchange or connected adapter. Missing partner contract cannot be replaced with Marketplace/Data Ventures auth. |
| X Ads | OAuth1 request-token/access-token user flow using guzzlehttp/oauth-subscriber1.0.2; encrypted10-minute request secrets and owner/provider-bound single-use state; account/funding currency discovery; separate campaign DAY BILLING/ENGAGEMENT report and setup/report UI | Fixture-tested only.1-7 completed local days,20 entities/request, ALL_ON_TWITTER only, no async backfill or nested conversions/revenue import. Rechecks campaign funding links/currency. Strict null/segment/array guards abstain rather than fabricate zero. No OAuth2 refresh. |

## Important distinctions

A partial reader is not a complete integration. A card is not a working connection. Empty reports do not certify permission. The system does not create advertiser accounts or obtain app approvals. Current adapters are read-only; no campaign mutation endpoints are offered.

Metrics must never be silently substituted across platforms. Installs, leads, desired actions and purchases differ. Conversion value can be advertiser-defined rather than sales. Privacy suppression is unknown, not zero. Report date inclusivity and timezone differ by provider. Reach/frequency are scoped estimates, not additive unique people.

Later local fetch-boundary scope and conservative refusals: ORIGINAL_PROVIDER_FETCH_BOUNDARY.md. No live provider completeness claim.
