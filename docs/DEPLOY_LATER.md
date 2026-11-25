# Production apply notes

## Verified deployed state, 25 November 2026

Production is the Vercel deployment of main at a88df8e, served at https://ad-platform-alpha.vercel.app. It was built from the GitHub repository RFSBFBD/AdPlatform and showed status Ready on the Vercel dashboard. Sign-in and read-only pages were checked. This release had no database migration. Preview deployments share the production database. The notes below are still not evidence of backups, monitoring, scheduled sync or any provider connection; those were not inspected.

## Historical deployment notes, not current-state evidence

The earlier deployment description has not been revalidated. This checkpoint did not inspect or change any hosted environment, build a Docker image, verify hosted login/database routing, enable scheduled sync or connect a live provider. Do not infer any current site availability or architecture from this file. The checked-in Vercel/container configuration is a deployment candidate, not a verified deployment.

Owner-controlled deployment requires a separate review of runtime, secret storage, authentication, database, backups, TLS, provider approvals and operational monitoring. Review the current source and provider matrix rather than assuming all providers share credentials or OAuth rules.

Before real provider connections are possible, the owner must provide:
1. Secrets in server env only: APP_KEY, DB credentials, provider app credentials (per provider, see PROVIDER_IMPLEMENTATION_MATRIX.md; for example Google needs a developer token). Nothing goes in the frontend.
2. OAUTH_ALLOWED_REDIRECT_URIS = the exact frontend /accounts URL registered with each provider.
3. Provider approvals for each provider you connect (see PROVIDER_IMPLEMENTATION_MATRIX.md). Without them real connection cannot be verified. The per-provider activation steps and official links are shown in the app: on Connected Accounts and inside each Developer API Hub credential card.
4. Leave AI_ALLOW_FAKE_PROVIDER unset. Fake provider output exists only for APP_ENV=testing and is rejected in production.
5. Do not enable preview sessions (ALLOW_PREVIEW_SESSION stays false).

First owner account: set ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD in the server env before seeding. The seeder has no built-in password and never overwrites an existing account's password.

Fresh-schema migrations were verified against a local synthetic PostgreSQL database. This does not verify a hosted PostgreSQL/Neon database or every SQLite migration path. Run `php artisan migrate` on your own environment; nothing is applied to production automatically.
