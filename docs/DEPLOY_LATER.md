# Deploying later (nothing here was executed against live systems)

Current state: https://ad-platform-alpha.vercel.app serves only the Angular frontend. `/api/*` returns the site HTML, so login cannot work there.
Before an end-to-end run is possible, the owner must decide and provide:
1. Where the Laravel backend runs (host, PostgreSQL). Set APP_ENV=production. Run `php artisan migrate` (includes business_profiles and the budget horizon rename).
2. Secrets in server env only: APP_KEY, DB credentials, provider app credentials (Meta, Google incl. developer token, TikTok). Nothing goes in the frontend.
3. OAUTH_ALLOWED_REDIRECT_URIS = the exact frontend /accounts URL registered with each provider.
4. Provider approvals (Meta, Google Ads, TikTok; X Ads API access for X). Without them real connection cannot be verified.
5. Route /api from the frontend host to the backend (the Vercel rewrite currently sends it to the SPA).
6. Leave AI_ALLOW_FAKE_PROVIDER unset. Fake provider output exists only for APP_ENV=testing and is rejected in production.
Do not enable preview sessions (ALLOW_PREVIEW_SESSION stays false).
