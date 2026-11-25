# Guided declared-lead scenarios

This milestone implements a bounded positive path for the original CURRENT / OPTIMIZED / CUSTOM interaction. It does not complete the original multi-provider business-outcome objective.

## Supported scope

- One owned advertising account, one immutable user-declared `lead` definition, campaign hierarchy, continuous `MAXIMIZE_LEADS` objective, explicit positive 30-day budget.
- Account, definition, objective and budget start blank. Loading accounts is an explicit action. No hypothetical owner choices are stored.
- Existing typed-source population matching, normalization evidence, completed account-local dates, quality checks and chronological response-fit validation still apply. No generic purchase count is substituted.
- Names are captured from the owned campaign hierarchy in the sealed result; source snapshots preserve fitted models and observations.

## Interaction

The guided card is in Evidence & Diagnostics. After loading accounts, choose the account, declared lead definition, objective and budget. The existing source tools below it can create immutable definitions and import aggregate counts, with their existing permissions and warnings.

CURRENT shows a modeled no-change allocation and the separate observed imported count. OPTIMIZED shows fitted response and a same-spend mix baseline. Neither is causal incremental response. Constraint flags and observed spend-support flags remain visible. The same-spend baseline is distinct from actual historical spend.

Custom budget fields start from the optimized allocation. `POST /api/v1/analytics/optimization-runs/{id}/custom-leads` evaluates the submitted exact campaign key set on the sealed archived curves. It checks owner, successful typed-lead campaign/continuous scope, engine version, input/result and source checksums, source-normalization guards, numeric domain and exact campaign coverage. It reuses `EntityScenarioComparison` rather than duplicating response/constraint formulas. It reports spend, modeled count, remaining cap, difference versus optimized, bound/cap violations and observed-support flags. Infeasible allocations are clearly marked and are not recommendations. A custom evaluation performs no metric query, refit, solve, archive write or campaign mutation. It is not saved as a new run.

Changing scope clears prior scenarios. Editing custom amounts clears the previous custom evaluation. Request-generation and input/run signatures reject stale responses.

## Verification

`GuidedLeadScenarioTest` creates a synthetic account/campaign pair, creates the lead definition through the API, imports 60 campaign/day count rows through the API, and performs a successful two-campaign solve. Spend histories are explicit local test fixtures carrying required normalization markers. This is not a provider-fetch or spend-semantics verification.

The custom response is independently recomputed from archived saturation formula parameters, not by calling the response-curve class. Replay and custom evaluation are checked after current spend/count deletion and changes to the clock, account currency and configuration; the original archive and archive count remain unchanged. Additional checks cover wrong owner, missing/extra campaign keys, invalid amounts, cap/bound violations, zero-spend support flags, altered result/source and wrong objective branch. Frontend tests cover blank owner choices, explicit scope, stale definitions/solve/custom/account reloads and invalid edits.

Final verification: 534 backend tests / 4219 assertions; 145 frontend tests; production build. Desktop and 390px mobile renderings use outputs from the API-backed synthetic test and are inspected visually. These checks establish local functionality, not real-account effectiveness.

## Boundaries retained

No publish, push, real-data update, account setup, campaign change, service purchase or new source delivery. Counts remain declared event credits, not verified lead quality, unique people, qualified leads or sales. Equal daily spend is assumed for period curve scaling. Checksums detect accidental mutation, not privileged database tampering. The Meta+Snap reported-purchase-credit branch remains blocked exactly as `REPORTED_PURCHASE_CREDIT_CONTRACT_HOLD.md` records.
