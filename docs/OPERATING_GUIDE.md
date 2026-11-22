# AdPlatform operating guide

Status: the OR-centric cleanup of November 2026 is the current version. README.md (Arabic) is the authoritative overview; this guide keeps the operating and OR-contract details. The platform is deployed at https://ad-platform-alpha.vercel.app (frontend + backend on Vercel, Neon Postgres), but no ad account was ever connected, no OAuth flow ran against a real provider, and no real AI model key was used.

## Apply
- Use the full source archive of this version. Backend: `composer install`, copy `.env.example` to `.env`, `php artisan key:generate`, `php artisan migrate`. Frontend: `npm ci`, `ng build`.
- First owner: set ADMIN_BOOTSTRAP_EMAIL / ADMIN_BOOTSTRAP_PASSWORD in the server env before seeding (the seeder has no built-in password and never overwrites an existing account).
- Production notes: read docs/DEPLOY_LATER.md.

## Run the tests
- Backend: `php vendor/bin/phpunit` (PostgreSQL per phpunit.xml). Current version: 184 tests / 1867 assertions, all green on native PostgreSQL 16.2 + FrankenPHP 1.14 / PHP 8.6 (independent review, 2026-11-22). Migrations also run on SQLite for local testing.
- Frontend: `ng test` (56 tests, all green) and `ng build`.

## What the Operations Research (OR) function does
- Objective: maximise total platform-reported revenue over Meta, Google and TikTok under a total budget, with R_i(S) = alpha_i (1 - exp(-beta_i S)); alpha_i = saturation_multiplier x observed revenue. The curve is an assumption you state, not a fitted or validated model.
- Budget: explicit amount and explicit period (daily is multiplied by 30). Channel limits need their own explicit period. A profile budget cap needs a valid period. Nothing is defaulted.
- Window: one common window of 30 complete days for every channel, ending at the oldest still-fresh channel's last synced day (never today). A channel without data on all 30 days is excluded with its reason; it is not scaled unless OR_ALLOW_PARTIAL_WINDOW_SCALING=true.
- Excluded with a reason (the rest still run): a channel whose currency is unknown, a channel with missing days in the window, a stale channel.
- Refused with no numbers: bad or non-finite input, mixed currency among usable channels, business currency different from the channels, min above max, an explicit limit on an excluded channel, fewer than two usable channels, an infeasible limit set, budget beyond the solver's numeric range, or a solution that fails its own check.
- Solver check (`solver_certificate`): budget residual, optimality residual and bounds, each with its tolerance.
- Comparison: "reallocation gain" = scenario minus your current mix scaled to the same total spend on the same curve (signed). Changes in total spend and revenue versus the observed 30 days are shown separately. Neither is profit or a forecast.
- Gates (3 days staleness, 5% maintain band) are uncalibrated engineering settings in config/or.php, returned with every response. The min-days setting (14) is not an effective gate, because the 30-complete-day window already requires all 30 days.

## Done
Honest login (no preview session), AI analysis computed server-side per provider/currency with fake providers only under APP_ENV=testing, OR engine and its tests, business profile (API + screen), accounts screen with OAuth return handling (code only), per-user tenant boundary (no Owner/Admin bypass), OAuth redirect allowlist and user-bound state, deploy-later and engine docs.

## Open, not done, or unverified
- X (Twitter): not implemented. Open requirement.
- OAuth: code and tests only; never run against a real provider; no provider approvals.
- The Vercel site serves frontend and backend and login works, but no real provider/OAuth/AI connection has ever run; end-to-end behaviour with live providers is unverified.
- Backend suite passes fully on native PostgreSQL 16.2 (184/1867); the earlier SQLite-only failures were SQLite limitations, confirmed fixed on PG.
- OR screen has controls for budget, period, saturation, budget mode (exact / at_most), minimum marginal ROAS and per-channel min/max with their own period, with client-side validation. Min marginal ROAS is accepted only with at_most (the screen disables it otherwise, and the backend answers constraints_required instead of ignoring it). at_most requires a floor. If at_most spends nothing the status is no_allocation (no numbers shown). A channel minimum above the budget is reported as "minimum exceeds budget". Changing any input hides the previous result and shows "Inputs changed" until Solve is pressed. The server validates and refuses on its own. Currency comes from the business profile and channels; no currency input and no conversion. Curve variance is not modelled.
- Very large budgets (about 100x observed spend) get status budget_beyond_numeric_range; a log-domain solver would extend the range (a design change, not done).
- Whether a missing-day channel should be excluded or extrapolated is a product decision (default: excluded).
- The response curve is an assumption; solver correctness says nothing about real ad results.
- Per-user vs Owner/team sharing model is undecided.
