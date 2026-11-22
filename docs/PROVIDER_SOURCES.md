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
