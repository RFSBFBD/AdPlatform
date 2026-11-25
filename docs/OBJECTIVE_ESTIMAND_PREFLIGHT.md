# Explicit objective and outcome-basis preflight

Local milestone after9a8c735. Engine7.5.0, snapshot schema2, objective-estimand-1. This is not a delivered package, live integration verification or completion of cross-platform budget optimization.

## Original requirement

The owner's original Master Spec, Nov23 15:33:55 EET, section13 requires the product user to choose an objective. Sections21-24 distinguish count and revenue response and portfolio ratios. Section40 permits the descriptive term platform-reported conversions, while disallowing unique-conversion claims without suitable deduplication. Section68 is an illustrative budget, not actual business input. None of these supplies an actual selected objective, data population or budget for the owner.

## Contract

Portfolio operations-research and suggestions requests accept explicit objective and outcome_basis. Both default to absent. Missing selection is an archived objective_basis_required refusal, not an inferred revenue goal. Existing malformed/unknown enum values receive validation errors. Known but unsupported objective/basis combinations return unsupported_objective_basis with the requested objective preserved. No other objective is substituted.

- MAXIMIZE_REPORTED_VALUE + platform_reported_credit records a source-reported attribution-credit scenario intent and proceeds to existing source/model/quality/constraint gates. Those gates still refuse cross-provider outcome pooling. Selection does not turn equivalence on.
- Other known reported count, business conversion, lead, revenue, ratio and target objectives are recognized as requests but not implemented on this portfolio path.
- common_business_events returns business_outcome_source_required. Missing dimensions explicitly name a common event definition, source/population, counting/value policy, attribution credit, date/currency basis and a portfolio adapter for that source. Advertising account login or matching declarations do not fill these gaps. No business event adapter was added.
- Entity optimization and its explicit objectives remain a separate supported surface with their existing source and numerical limits. This milestone does not rename or expand entity objectives.

The versioned preflight contains exact selections, missing inputs, reporting window, source-account-currency contracts, a source-evidence hash, and unverified source dimensions. Current source contracts retain raw field names but do not independently establish configured event population, counting/value rules or report-date basis. Attribution remains marked unverified where the importer says so. Selection is intent, not supporting source evidence. No verified sales, profit, unique people/events, causal response or common business equivalence claim is made.

## Descriptive arithmetic

Reported counts and value can still be added within a source-account-currency group over distinct account-days when existing numeric/account/semantic checks pass. Multiple hierarchy rows on a day conservatively withhold that group's sum, rather than double-count. Invalid/overflow operands withhold sums. Missing or different contracts withhold that group only. No data is repaired.

The response includes platform_reported_conversions and platform_reported_conversion_value with source labels and contract evidence. There is no mixed-action total, common business total, inferred currency conversion or mixed-family CPA/ROAS. Different groups may count the same person or event. Keeping groups separate does not claim that descriptive cross-source arithmetic is mathematically impossible or that additive reported events require disjoint people. It avoids presenting unlike actions or values as a single operational business objective.

The existing overview spend/delivery and grouped KPI behavior is unchanged. This milestone adds descriptive source groups alongside portfolio preflight refusals rather than weakening overview or analytical semantic guards.

## Archive and window binding

Source snapshot schema2 stores the preflight, exact window and source rows. Inputs and result store selected objective/basis; existing input/result and source checksums bind the record. Checksums detect accidental edits, not privileged database tampering. Missing or inconsistent portfolio preflight is not retroactively inferred for old archives.

A sealed refused portfolio archive remains readable as a refusal with descriptive evidence even though no joint semantic model exists. Replay returns not_computable, never fits or runs curves for that refusal. Successful scenarios still require the existing source-definition guard. The assumed mode's common complete-data window is reused even when intent is absent, so suggestions, descriptive rows and archive use that window. Fitted mode keeps its existing window. No current account access is required to inspect the stored refusal.

## UI and verification

Objective and outcome-basis controls start blank. Changing either hides the previous result; requests send only explicit selections. The page displays preflight missing dimensions and source-reported groups, without invented zeros or an allocation. Desktop uses a two-column form with labels and help on separate lines, full-width fields and persistent JSON examples. Mobile uses one column. Both were inspected as pixels with synthetic payloads; no horizontal overflow was present. These screenshots do not prove real source semantics or live connectivity.

Full local PostgreSQL16 suite:531tests/4169assertions, no tests removed or skipped. Adds10backend cases to521 and2frontend cases to137, frontend139tests and production build pass. Existing semantic-refusal cases now send explicit reported intent so they continue testing source refusal rather than only the earlier missing-intent gate. Existing pure numerical/entity/archive tests remain.

## Remaining capability limits

Operational cross-provider portfolio OR is still unavailable. A true common-business event/value adapter, source population/counting/value/attribution/date evidence and supported objective router must precede that claim. Explicit reported-credit optimization could be developed separately against its own documented estimand and validated model, but this milestone does not enable it through a declaration bypass. No owner decision about an imaginary scenario was requested. No live API calls, production edits, campaign changes, account setup, purchases, deployment, push or new ZIP were made.
