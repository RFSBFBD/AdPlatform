# Analyst evidence contract

`GET /api/v1/analytics/suggestions` returns the mathematical evidence that its opt-in AI ordering step can see. Request `ai=true` only with the current `snapshot_id`; changed data causes409 before any model request.

The six blocks are built from the same stored optimization payload and window used to generate suggestions:

- `actual_metrics`: ACTUAL included channel spend, reported conversion value and ROAS. It is not a sum over all accounts and not unique cross-platform revenue. When the solver abstains, no placeholder zero is promoted into actual evidence.
- `forecast`: EXPECTED, explicitly not computed for this snapshot. No fake forecast or interval.
- `optimization`: SCENARIO objective, budget, modeled value, budget menu/allocation operands and solver certificate. No campaign execution.
- `diagnostics`: explicitly not computed in this snapshot. Allocation changes do not establish campaign causes.
- `confidence`: calibrated allocation confidence remains null. Engineering stability and stress availability do not become probabilities.
- `data_quality`: existing portfolio gate and window, labelled as an engineering rubric.

The builder uses an allowlist, not an arbitrary serialization of all source rows/accounts. It excludes account IDs, names, access tokens, emails, raw rows and sync errors. Provider API credentials are handled by the existing BYOK transport, not inserted into evidence. A configured external model receives these scoped mathematical operands only when the user asks for AI ordering.

The AI's output contract is deliberately narrower than an analyst chatbot: a complete nonduplicated permutation of suggestion indexes and compatible codes from a closed list of server-written notes. It cannot change operands, produce campaign writes or display its own numerical claims. Rejected output keeps deterministic suggestions. General Q&A, free prose reports, independent forecast computation and campaign diagnostics aggregation remain separate unfinished work.

Validation includes outbound HTTP fake inspection for all six blocks, immutable suggestion evidence, tenant configuration boundaries, source-change snapshot rejection, and empty/failed-result abstention. No live model or ad account was used in these checks.

## Closed questions over archived entity scenarios

`POST /analytics/optimization-runs/{id}/explain` accepts one of four fixed question keys: what_is_optimized, how_allocation_changed, why_not_confident, what_are_limits. Owner-scoped entity archives only, snapshot presence/checksum required. The deterministic server response reads stored objective, operands, actual/scenario spend change, comparison, certificate and limits, with no model API, live metrics or provider request. It explicitly preserves null confidence and distinguishes stress, robust assumptions and engineering stability. It does not independently revalidate the stored result or make the checksum tamper-proof. The UI exposes fixed questions and exact evidence, not free-form model-generated advice. This is a bounded explanation surface, not completion of general AI Q&A or report generation.

New archives also have a creation-only checksum over stored result/inputs and version metadata. Explanation rejects mismatches and returns result_integrity; source-only legacy archives are labeled legacy_unsealed. This detects accidental changes, not malicious edits by a database administrator who can replace hashes. Stored-result explanation is still not independent numerical verification.

## Stored scenario HTML report

Owner-only GET /analytics/optimization-runs/{id}/report downloads a self-contained escaped HTML attachment for successful entity archives after source/result integrity checks. Original objective, scope/window, units, actual versus modeled output, allocation, same-spend baseline and caveats remain distinct. Mobile cards and print CSS are included, no external assets/scripts. Authenticated frontend downloads the response as a local Blob, not a public link. The report is deterministic archived evidence, not AI prose, live diagnostics or independent validation. Sharing remains the owner's separate decision. Legacy unsealed and unchecked-menu limitations remain visible. No report publication service or general analyst report generation is claimed.
