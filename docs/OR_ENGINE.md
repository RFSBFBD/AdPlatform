> Historical assumed-curve reference. This file documents earlier engine iterations and contains superseded UI/testing claims. For current local operation read OPERATING_GUIDE.md, current scope MATH_SPEC_COVERAGE.md, and discrete semantics EXTERNAL_SOLVER.md. Both assumed and fitted routes now exist; this older reference is not the complete current engine contract.

# OR engine: what it does and does not claim

Status: experimental scenario tool. Output is never an operational recommendation.

Inputs (all explicit, none defaulted): `budget`, `saturation_multiplier` (> 1, assumed revenue ceiling as a multiple of observed revenue),
optional `budget_mode` (`exact` | `at_most`), `min_marginal_roas` (required for `at_most`), optional `channel_limits[provider] = {min, max}`.
Without a channel max, the only bound is the total budget (arithmetic, not policy).

Data comes only from the signed-in user's connected accounts (`performance_metrics.provider`), last 30 days.
Quality gates (engineering defaults, adjustable in code): >= 14 days with data, latest day within 3 days, spend > 0, revenue > 0, a single currency.
Failing channels are excluded and listed in `warnings`. Mixed currency across channels abstains (no conversion is applied).

Statuses: `ok` (scenario), `constraints_required`, `insufficient_data`, `mixed_currency`, `infeasible` (no allocation fits the limits),
`at_most` mode may under-spend by design and is not `infeasible`.

Revenue is platform-reported conversion value. It is not profit and not unique sales; conversions overlap across platforms.
The response curve is assumed, not fitted. Nothing is executed or spent.

## Horizon, currency, caps (added after the first version)
- `budget_horizon` (`daily` | `per_30_days`) is required. The data window is 30 days; `daily` is multiplied by 30 by an explicit rule. A bare "monthly" is never assumed equal to the window.
- The profile's `budget_cap` needs `budget_cap_horizon`; the requested budget above the cap abstains (`exceeds_user_cap`). The cap is never exceeded.
- If the profile currency differs from the channel currency the engine abstains (`currency_mismatch`). No conversion is done; original platform figures are untouched.
- `custom_fields` are untrusted notes: not used by the engine, no secrets allowed.
- Target ROAS and margin are never assumed; they stay empty until the user fills them.

## v4 solver checks
- Objective: maximise sum of R_i(S_i) = alpha_i(1-exp(-beta_i S_i)) with sum S_i = budget (exact) or <= budget (at_most with an explicit min marginal ROAS), min_i <= S_i <= max_i. The curve is an ASSUMPTION (alpha = saturation_multiplier x observed revenue); the solver's optimality says nothing about real results.
- The result carries `solver_certificate`: budget residual, KKT stationarity/complementarity residual, bounds check, each with its tolerance (budget 1e-8 relative or 1e-6 absolute; stationarity 1e-6 relative to lambda). Failing any check returns status `solver_not_converged` with no numbers.
- Refused with no numbers: non-finite or non-positive budget, negative or non-finite limits, min above max, unknown channel currency, mixed or mismatching currency, missing horizons, cap without a valid period.
- Observed totals with fewer than 30 data days are scaled linearly to 30 days and the response says so.
- MAINTAIN label: relative band (`OR_MAINTAIN_BAND_PERCENT`, default 5), a display label only, reported in `gates`.
- Rounded display values can differ from the exact budget by a few cents in the sum.
- Partial windows: by default a channel with fewer than 30 data days is EXCLUDED and the reason is returned (fail closed). Linear extrapolation is only possible when `OR_ALLOW_PARTIAL_WINDOW_SCALING=true`, and then it is disclosed in `warnings`. The 14-day gate only matters in that mode.
- Limits given for a channel with no data are not applied, and the response says so. Warnings and excluded channels are shown in the UI for both ok and refused results.
- Very large budgets: when the assumed curve is so saturated that marginal return per unit falls below 1e-12, the status is `budget_beyond_numeric_range` (a numeric limit, not a proof of infeasibility). In a 600-case local fuzz with budgets up to 200,000 and spends of about 1,000 to 20,000, 599 solved and passed the certificate; the one refusal was this case (budget about 100x observed spend).
- config/or.php was empty in earlier packages (so env settings were inactive); v5 restores it and a test checks the keys load.

## v6 comparison rules
- "Reallocation gain" = scenario revenue minus the CURRENT channel mix scaled to the same total spend, both on the same assumed curve. It is signed (negative when stated limits force a worse mix). It is not profit and not a forecast.
- The change in total spend and in revenue versus the observed 30 days is returned separately in `comparison` (spend_change_vs_observed, revenue_change_vs_observed). Per-channel `lift_vs_baseline` adds up to the reallocation gain, and the directive cards use the same baseline.
- Window: one common 30-complete-day window for all channels, ending at the oldest still-fresh channel's last synced day (never today). A channel with fewer data days (gaps) is excluded with "only N of 30 days", unless partial-window scaling is explicitly enabled. Covered by DB-row tests (OrWindowDbTest: complete, two-days-old, gap, stale, one stale channel).
- UI controls: budget, period and saturation only. Per-channel limits, at_most mode and the minimum marginal ROAS are API-only (open item); the screen says so.
- The window SQL ran on SQLite only (not Postgres). It uses whereDate; the first v6 version compared date strings and was wrong on SQLite.
