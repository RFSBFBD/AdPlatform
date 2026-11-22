# Production apply notes

Current state: https://ad-platform-alpha.vercel.app serves the Angular frontend AND the Laravel backend (Vercel multi-service: `/api/*` routes to the backend container, everything else to the SPA). Database: Neon Postgres. Login works there.

What is still NOT done on the live site: no ad provider account was ever connected, no OAuth flow ran against a real provider, no real AI model key was used, and scheduled sync is not enabled (sync is the manual Sync button).

Before real provider connections are possible, the owner must provide:
1. Secrets in server env only: APP_KEY, DB credentials, provider app credentials (Meta, Google incl. developer token, TikTok, Snapchat). Nothing goes in the frontend.
2. OAUTH_ALLOWED_REDIRECT_URIS = the exact frontend /accounts URL registered with each provider.
3. Provider approvals (Meta, Google Ads, TikTok, Snapchat). Without them real connection cannot be verified. The per-provider activation steps and official links are shown in the app: on Connected Accounts and inside each Developer API Hub credential card.
4. Leave AI_ALLOW_FAKE_PROVIDER unset. Fake provider output exists only for APP_ENV=testing and is rejected in production.
5. Do not enable preview sessions (ALLOW_PREVIEW_SESSION stays false).

First owner account: set ADMIN_BOOTSTRAP_EMAIL and ADMIN_BOOTSTRAP_PASSWORD in the server env before seeding. The seeder has no built-in password and never overwrites an existing account's password.

Migrations run on PostgreSQL 16 (tested on 16.2) and SQLite; production path is PostgreSQL/Neon. Run `php artisan migrate` on your own environment; nothing is applied to production automatically.
