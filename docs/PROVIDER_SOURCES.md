# Provider sources (read on 2026-11-22)

Every claim in the connection guides and the Snapchat adapter comes from these pages. Nothing here was run against a live account.

## TikTok (Marketing API, not TikTok Shop)
- Portal: https://business-api.tiktok.com/portal
- Get started (5 steps): https://business-api.tiktok.com/portal/docs/marketing-api-get-started/v1.3
- Register as a developer (company email on a verified domain, public company website, no personal accounts): https://business-api.tiktok.com/portal/docs/register-as-a-developer/v1.3
- Create a developer app (redirect URLs up to 10, scopes): https://business-api.tiktok.com/portal/docs/create-a-developer-app/v1.3
- Advertiser authorization (auth_code valid 1 hour, one use): https://business-api.tiktok.com/portal/docs/marketing-api-authorization/v1.3
- App permissions: https://business-api.tiktok.com/portal/docs/app-permissions/v1.3

## Meta
- Ads Management Standard Access: https://developers.facebook.com/docs/features-reference/ads-management-standard-access/
- Permissions reference (App Review, Business Verification for Advanced Access): https://developers.facebook.com/docs/permissions/

## Google Ads
- Quick start (developer token, manager account, OAuth, login-customer-id): https://developers.google.com/google-ads/api/docs/get-started/make-first-call

## Snapchat (Snap Marketing API)
- Access (Ads Manager > Business Dashboard > Business Details > + OAuth App): https://businesshelp.snapchat.com/s/article/api-apply?language=en_GB
- Authentication (authorize URL, scope snapchat-marketing-api, 60 minute token, refresh): https://developers.snap.com/marketing-api/Ads-API/authentication
- Quick start (Organization Admin role, base URL https://adsapi.snapchat.com/v1): https://developers.snap.com/marketing-api/Ads-API/quick-start
- Organizations (GET /v1/me/organizations?with_ad_accounts=true): https://developers.snap.com/marketing-api/Ads-API/organizations
- Ad accounts (currency and timezone are per account): https://developers.snap.com/marketing-api/Ads-API/ad-accounts
- Measurement: https://developers.snap.com/marketing-api/Ads-API/measurement
  - `GET /v1/adaccounts/{id}/stats`, granularity DAY, start/end at the account timezone day boundary.
  - `spend` is micro-currency (1,000,000 = 1.00). Metrics are final 48 hours after the end of the account day.
  - `conversion_purchases` and `conversion_purchases_value` (micro-currency of the account) exist only for ads tracked with Snap Pixel.
  - Attribution defaults: swipe-up 28_DAY, view 1_DAY. The adapter sends both explicitly.

## Not verified (so not built)
- Campaign list response shape (the docs page was cut off). Snapchat reporting is account-level only.
- The `breakdown=campaign` response shape.
- Any maximum DAY range per request. The adapter pulls in 30-day chunks as a cautious choice.
- Pinterest, LinkedIn, X: not researched or built. X stays an open requirement.


## Reach and frequency addition, 2026-11-23

Official reference inspected: https://developers.facebook.com/docs/graph-api/reference/adaccount/insights/
Campaign description inspected: https://developers.facebook.com/docs/marketing-api/reference/ad-campaign-group/insights?locale=vi_VN

Meta exposes reach and frequency as estimated metrics. The adapter requests them on
its existing campaign/day query and stores each exact row without adding across
entities or dates. Missing fields stay null. `/api/v1/analytics/audience` returns
scoped rows, not a unique audience total. Fixture tests verify mapping and absence.
No live token or availability assertion is implied by these tests.


## Taboola, contract checked 2026-11-23

- https://developers.taboola.com/backstage-api/reference/client-credentials-flow
- https://developers.taboola.com/backstage-api/reference/authentication-basics
- https://developers.taboola.com/backstage-api/reference/get-allowed-accounts
- https://developers.taboola.com/backstage-api/reference/campaign-summary-report

Implemented: client_credentials, allowed-accounts, campaign-summary/day reporting,
explicit regrant on expiration, administrator-only credential discovery and
existing tenant-bound import flow. No browser authorization-code URL is invented.
Missing conversion/value fields stop import rather than become false zeroes.
Fixtures exercise auth/mapping/missing fields. Live access and permissions remain
unverified. Reporting is account/day only. Clicks can revise for 48 hours and
conversions for 30 days. The wider platform must not treat these as final sales.

## Pinterest API v5 (2026-11-23)
- https://raw.githubusercontent.com/pinterest/api-description/main/v5/openapi.yaml (official 5.32.0 contract, parsed directly)
- https://developers.pinterest.com/docs/getting-started/set-up-authentication-and-authorization/
- https://developers.pinterest.com/docs/analytics-and-reports/ads-reporting/
Implemented authorization-code/rotating refresh, bookmark account discovery and account/day synchronous reports. USD accounts only pending documented non-USD column semantics. Outbound clicks, checkout counts/value; explicit 30-day click and engagement, 1-day view windows, ad-action reporting. Dates restricted to 90-day synchronous history. Full hierarchy and historical async reports not implemented. Missing metrics refuse import. Fixture tested, not live verified.

## Reddit API v3 (2026-11-23)
- https://ads-api-reddit.netlify.app/specs/reddit-ads-api-v3.yaml (official schema extract inspected)
- https://ads-api-reddit.netlify.app/docs/v3/api/get-a-report
- https://ads-api-reddit.netlify.app/docs/v3/guides/programs/business/fetch-accounts
Implemented adsread OAuth, refresh, businesses/accounts discovery, guarded same-origin and same-path pagination, POST-body-preserving reports. Account/day UTC purchase count = click+view, reported total purchase value /100, spend /1e6. Raw metric units are NOT uniform. Account-configured attribution retained, not deduplicated. Campaign hierarchy/breakdowns not implemented. Fixture tested, not live verified. Empty data does not certify report permission.

### Pinterest units correction (verified 2026-11-23)
https://developers.pinterest.com/docs/analytics-and-reports/metrics-glossary/ explicitly defines SPEND_IN_MICRO_DOLLAR as advertiser microcurrency. The temporary USD-only restriction has been removed; account currency is still mandatory. Checkout value is event-supplied value, not audited sales or profit.

## Spotify Ads API v3 (2026-11-23)
- https://developer.spotify.com/reference/ads-api/v3/api.yaml (official OpenAPI 3.0.0)
- https://developer.spotify.com/documentation/ads-api/quick-start
- https://developer.spotify.com/documentation/ads-api/reference/v3.0/getAggregateReport
- https://github.com/spotify/ads-agentic-tools/blob/main/skills/api-reference/references/api-behaviors.md (SPEND report values in billing currency, unlike budget micro-units)
Implemented auth-code/refresh, businesses[] then ad_accounts[] discovery, account/day reports with repeated fields query keys, UTC inclusive end day, continuation-token-only pagination. Purchase/revenue instrumentation required: missing values or privacy sentinel -5 refuse the entire import. Warnings refuse silent partial reporting. No campaign hierarchy. Test accounts produce no events and cannot establish real reporting. Fixture-tested only, never live verified.

## LinkedIn (2026-11-23)
- https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/ads-reporting?view=li-lms-2026-11
- https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/ads-reporting-schema?view=li-lms-2026-10
- https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/conversion-tracking?view=li-lms-2026-04
- https://www.linkedin.com/help/linkedin/answer/a420851/reporting-time-zones-for-ads?lang=en
- https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/create-and-manage-accounts?view=li-lms-2026-05
- https://learn.microsoft.com/en-us/linkedin/shared/authentication/programmatic-refresh-tokens
Implemented auth-code, optional partner-only refresh, configurable YYYYMM version, cursor account discovery, regular ACCOUNT daily analytics with UTC inclusive date bounds and 15000-row cap detection. No campaign hierarchy. Reports disabled by default: externalWebsiteConversions are advertiser-defined desired actions including click/view and are not purchases or Bernoulli successes per click. conversionValueInLocalCurrency is attributed advertiser-defined value, not proven sales. Deployment may enable reporting with ProviderDeveloperConfig.extra_settings.conversion_values_verified_as_revenue=true ONLY after its conversion rules and value definitions are verified. This setting is deliberately not an end-user toggle. No live verification. Empty results do not prove healthy permissions.

## Apple Ads Platform API 1.0 (2026-11-23)
- https://developer.apple.com/documentation/apple-ads-platform-api/access-overview
- https://developer.apple.com/documentation/apple-ads-platform-api/implementing-oauth-for-the-apple-ads-platform-api
- https://developer.apple.com/documentation/apple-ads-platform-api/get-app-campaign-reports
- https://developer.apple.com/documentation/apple-ads-platform-api/appscampaignreportresponse
- https://developer.apple.com/documentation/apple-ads-platform-api/appscampaignreportrow
- https://developer.apple.com/documentation/apple-ads-platform-api/timerange
These Apple DocC pages were read via their matching tutorials/data JSON representation. Implemented client grant with deployment-provided pre-signed ES256 client-secret JWT (encrypted), regrant, ACL account discovery with currency/timezone, adAccountId context and separate paginated campaign/day delivery reports. Standard revenue sync fails closed because installs are not purchases/revenue. GET /api/v1/ad-accounts/{id}/apple-delivery-report?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD is owner-scoped and returns delivery-only typed rows without raw credentials. Single-day requests omit granularity; multi-day requests DAILY; explicit UTC. JWT signing/rotation is not built. No private keys requested or stored. Fixture tested only, not live verified.

## Outbrain Amplify (2026-11-23)
- https://developer.outbrain.com/docs/amplify/api/reference/list-marketers/
- https://developer.outbrain.com/docs/amplify/api/reference/list-budgets-for-marketer/
- https://developer.outbrain.com/docs/amplify/api/reference/retrieve-periodic-performance-statistics-for-marketer/
- https://developer.outbrain.com/docs/amplify/api/reference-types
Implemented provisioned-token reader (OB-TOKEN-V1), marketers/budgets discovery and marketer/day periodic native report mapping with pagination. Currency comes only from consistent budget currencies; missing/conflicting budgets yield null. Report conversion date is explicit, totalConversions and totalSumValue include click/view. ET reporting offset/DST unresolved in current docs. Unified revenue-table import intentionally disabled. Discovery returns timezone=null and readiness=blocked_timezone_definition, so account linking also refuses rather than guessing IANA zone. Reader can be used by a deployment's controlled token workflow; UI token onboarding is not built. Password login and refresh are not fabricated. Native report data cannot enter pooled OR. Fixture tested only.

## Microsoft Advertising REST v13 (2026-11-23)
- https://learn.microsoft.com/en-us/advertising/customer-management-service/getuser?view=bingads-13
- https://learn.microsoft.com/en-us/advertising/customer-management-service/searchaccounts?view=bingads-13
- https://learn.microsoft.com/en-us/advertising/customer-management-service/paging?view=bingads-13
- https://github.com/MicrosoftDocs/Advertising/blob/main/advertising/bingads-13/reporting-service/includes/request-header-rest.md
- https://github.com/MicrosoftDocs/Advertising/blob/main/advertising/bingads-13/reporting-service/submitgeneratereport.md
- https://learn.microsoft.com/en-us/advertising/reporting-service/pollgeneratereport?view=bingads-13
- https://learn.microsoft.com/en-us/advertising/guides/request-download-report?view=bingads-13
Implemented Entra auth-code/refresh and GetUser/SearchAccounts (explicit paging). REST uses Bearer Authorization, never SOAP AuthenticationToken. Reporting submit/poll carry CustomerId,CustomerAccountId,DeveloperToken, a derived AccountPerformanceReportRequest and explicit provider ReportTimeZone enum. State is submitted_not_imported until actual archive processing. ZIP download/CSV canonical import, timezone enum-to-IANA normalization, persistent report orchestration and UI onboarding NOT built yet. fetchInsights fails explicitly, never returns an empty successful sync. Local conservative report deadline is 23h because official references disagree 1 vs 2 days. Download URL expires in 5 minutes. No live verification.

### Microsoft archive parser extension
https://learn.microsoft.com/en-us/advertising/guides/reports?view=bingads-13 confirms Daily TimePeriod=yyyy-mm-dd. Request uses FormatVersion2.0 to avoid thousands separators in monetary fields. Bounded ZIP reader now implemented with no filesystem extraction and account/currency/date/metric validation. The 20MB compressed/100MB uncompressed/single-member limits are local safety policy, not vendor quotas. Persistent async wiring and download host validation still pending, so canonical sync remains fail-closed.

### Microsoft persistent workflow extension (2026-11-23)
ProviderReportJob persists submission/deadline/poll time/state; queue worker polls at120 seconds, refreshes the same account token, downloads immediately when successful, parses archive and imports via the normal validated sync path. UI has async request/status, never immediate success. Single ready job idempotently imports. Sync queue driver is refused. Exact HTTPS download-host allowlist is empty by default until deployment verifies its vendor host; no redirect or Authorization on signed download. Provider TimeZone enum-to-IANA map is also deployment supplied, empty by default. These are prerequisite gates, not guessed account settings. Owner-scoped status hides URL and token. Archive processing and ready-to-import workflow have fixture tests; no live report has run.

## Amazon Ads current bounded implementation (2026-11-23)
- https://d1y2lf8k3vrkfu.cloudfront.net/openapi/en-us/dest/OfflineReport_prod_3p.json (Reporting v3 OpenAPI inspected)
- https://d3a0d0y2hgofx6.cloudfront.net/openapi/en-us/profiles/3-0/openapi.yaml (profiles OpenAPI inspected)
- https://github.com/amzn/ads-advanced-tools-docs/blob/main/postman/Amazon_Ads_Unified_API_Environment.postman_environment.json (regional hosts)
Added authorization-code/refresh, explicit NA/EU/FE region gate, profile array discovery using profileId (not accountInfo.id), documented Sponsored Products report submission and status mapping. Completed URL requires nonexpired returned urlExpiresAt; no fixed hour assumed. PENDING/PROCESSING never claim downloaded/imported data. GZIP_JSON is not ZIP. Persistent workflow/download/canonical parser/UI still in progress. No live verification or region eligibility inferred from a country enum.

### Amazon SP completed-report path

- https://d3a0d0y2hgofx6.cloudfront.net/en-us/guides/reporting/v3/columns.md
- https://d3a0d0y2hgofx6.cloudfront.net/en-us/guides/reporting/v3/report-types/campaign.md
- https://d3a0d0y2hgofx6.cloudfront.net/en-us/guides/reporting/v3/get-started.md
- https://d3a0d0y2hgofx6.cloudfront.net/openapi/en-us/profiles/3-0/openapi.yaml
- https://d1y2lf8k3vrkfu.cloudfront.net/openapi/en-us/dest/OfflineReport_prod_3p.json

Implemented SP campaign-only grouping, DAILY GZIP_JSON, purchases7d/sales7d selected together, never summed with 14d columns. Read profiles with apiProgram=report/accessLevel=view. Preserve profile region/currency/timezone and IDs with bigint-safe JSON parsing. Local caps20MB compressed/100MB decoded/500k campaign rows, not vendor quotas. Poll every5min with retries/backoff and4h local timeout; source says generation may take3h. URL expiry comes from urlExpiresAt; no bearer token/redirect on signed download. Exact approved host list and verified request endDate semantics are deployment prerequisites, both fail closed by default. No universal marketplace success claim. Reporting v3 deprecation June30,2027 requires migration.

## X Ads source-derived delivery implementation, checked2026-11-23
- https://docs.x.com/resources/fundamentals/authentication/api-reference.md OAuth1 POST token endpoints are api.x.com. Table labels JSON while literal examples are form-encoded. Both parser paths tested. Exact registered callback preserved; server-generated state is recovered by frontend storage and checked against owner/provider plus returned request token.
- https://docs.x.com/x-ads-api/analytics.md stats arrays nested under entity/id_data/metrics; id_data represents segments, not dates. DAY uses account-local boundaries, exclusive end. Synchronous7-day maximum,20entities. Current fixtures derive DAY shape from the documented nested arrays, not from a successful DAILY wire capture. Nulls remain unknown even where vendor UI displays zero.
- https://docs.x.com/x-ads-api/fundamentals/currency.md micros /1e6 established by the explicit5.50USD=5,500,000example, not the contradictory prose instructing multiplication. Funding instrument currency, account timezone, campaign-to-funding linkage. Preliminary billing can restate.
- https://docs.x.com/x-ads-api/campaign-management/reference.md funding instrument discovery and campaign linkage.
- https://github.com/guzzle/oauth-subscriber and https://packagist.org/packages/guzzlehttp/oauth-subscriber maintained OAuth1 middleware1.0.2 pinned in composer.lock, no homemade cryptographic signer. Composer audit at install returned no advisories. This does not guarantee absence of future vulnerabilities.
