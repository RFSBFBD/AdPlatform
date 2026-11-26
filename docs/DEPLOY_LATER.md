# Production apply notes

## Verified deployed state, 26 November 2026

Production is the Vercel deployment of main at e7f9d04, served at https://ad-platform-alpha.vercel.app. The Vercel dashboard showed it as Ready, Production, Current, built from RFSBFBD/AdPlatform main. Sign-in, the Dashboard and the Team & Access page were opened read-only. This release changed the frontend only: no backend code, no database migration. Its content is the Team role change and removal controls plus the Dashboard data freshness card. The deployed JavaScript contains both features.

Not verified live: the role change and Remove controls (the production account has one member, so they do not render, and removal is not exercised because previews and production share one database), and the freshness card with data (no provider account is connected, so the card is hidden by design). Both were checked only against a throwaway database and fixtures before release. Preview deployments share the production database. Backups, monitoring, scheduled sync and every provider connection were not inspected.

## Historical deployment notes, not current-state evidence

The earlier deployment description has not been revalidated. This checkpoint did not inspect or change any hosted environment, build a Docker image, verify hosted login/database routing, enable scheduled sync or connect a live provider. Do not infer any current site availability or architecture from this file. The checked-in Vercel/container configuration is a deployment candidate, not a verified deployment.

Owner-controlled deployment requires a separate review of runtime, secret storage, authentication, database, backups, TLS, provider approvals and operational monitoring. Review the current source and provider matrix rather than assuming all providers share credentials or OAuth rules.

Before real provider connections are possible, the owner must provide:
1. Secrets in server env only: APP_KEY, DB credentials, provider app credentials (Meta, Google incl. developer token, TikTok, Snapchat). Nothing goes in the frontend.
2. OAUTH_ALLOWED_REDIRECT_URIS = the exact frontend /accounts URL registered with each provider.
3. Provider approvals (Meta, Google Ads, TikTok, Snapchat). Without them real connection cannot be verified. The per-provider activation steps and official links are shown in the app: on Connected Accounts and inside each Developer API Hub credential card.
4. Leave AI_ALLOW_FAKE_PROVIDER unset. Fake provider output exists only for APP_ENV=testing and is rejected in production.
5. Do not enable preview sessions (ALLOW_PREVIEW_SESSION stays false).

First owner account: set ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD in the server env before seeding. The seeder has no built-in password and never overwrites an existing account's password.

Fresh-schema migrations were verified against a local synthetic PostgreSQL database. This does not verify a hosted PostgreSQL/Neon database or every SQLite migration path. Run `php artisan migrate` on your own environment; nothing is applied to production automatically.
