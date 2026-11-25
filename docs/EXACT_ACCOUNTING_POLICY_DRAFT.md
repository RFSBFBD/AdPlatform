# Exact recorded accounting policy, draft for integrated acceptance

Version proposed: exact-recorded-v1. Scope: source-recorded arithmetic only, not truth of the source, deduplicated customers, future accuracy, model identification or exact global optimization.

## Rules already fixed by owner intent

1. Never silently round source precision. Accept original decimal tokens/integer tokens, preserve their original lexeme, unit and scale in an immutable snapshot. Native binary floats are not source-exact tokens, even when they print a plausible decimal. Refuse them in exact accounting. Source JSON must be decoded without first losing decimal text; a later string cast cannot restore it.
2. Mathematical value and source precision are separate. 1.2300 and 1.23 have equal rational value but different original recorded scales. Both originals remain in source evidence. Summing may align scales exactly, never truncate.
3. Counts explicitly described as integral reject fractional values. Provider-attributed conversion credits may be fractional if the verified contract says so; never relabel them unique people or integer purchases. Zero is an observed zero only when documented evidence says so. Missing, suppressed, omitted and unknown are not zero.
4. Currency is part of a money unit. No implicit conversion or cross-currency total. Source micros may divide exactly by1000000 only with a documented source contract. Do not apply currency minor-unit rules to an undocumented reporting field. Reach/frequency and ratio aggregation require their own population rules, not blind summation.
5. Exact recorded arithmetic uses arbitrary-length integers, finite decimals and reduced rational numbers. Division by zero returns undefined with reason. Nonterminating ratios are exposed as numerator/denominator, not a fabricated exact decimal. All exact API operands are strings, never JSON numbers that can lose precision in a browser.
6. Floats exist only beyond an explicit approximate-model boundary. Preserve the exact operands and document conversion loss/range/tolerance. A model that cannot safely represent its admitted magnitude must refuse. A tolerance cannot legalize an exact recorded budget overspend, invalid count or source mismatch.
7. The original ledger must not inherit DECIMAL(14,4)/DECIMAL(18,4) limitations. Persist exact lexemes/normalized strings and source evidence in additive immutable records. Declare and enforce transport/resource limits, reject input beyond them explicitly. No unsupported-scale silently accepted into old columns.
8. Existing rows that passed through float or four-place casts remain legacy precision-unverified. Do not reconstruct original precision from those rows, alter their historical values, or promote them to exact. A verified retained raw token could support a new separately versioned snapshot only after authorized, validated reconstruction. No historical repair is authorized now.
9. Exact ledger reconciliation checks raw-to-canonical transforms, row keys/duplicates, completeness and totals. Different source definitions/windows/populations cannot become equivalent by summation. Scope/unit mismatch is a hard error. Every contribution is traceable to original source lexeme plus transform.
10. All displayed actual totals/ratios must reference these strings/rationals. Approximate model output is explicitly EXPECTED or SCENARIO, with tolerance, horizon and assumptions. An exact historical total is not evidence of predictive validity.

## Consequential choices not to commit silently

- Display settlement and formatting: no requirement yet defines preferred currency decimal places or rounding mode. Safe provisional behavior is show exact decimal actuals and rational derived values without discarding digits; if a shortened visual preview is later wanted, label it approximate and retain exact operands. No particular2-decimal settlement rule is adopted.
- Budget quantum: money source precision is not a permissible allocation increment. Continuous numerical allocation, account currency settlement quantum and provider budget minimum are different. Require a verified/configured budget quantum in the explicit problem before quantized allocation is accepted. Do not assume one cent, one pound or one micro, and do not infer it from fixture values. When unspecified, continuous modeled allocations remain approximate, never executable approved budgets.
- Overlapping audience variables: no interaction/dedup assumption selected. A laminar/disjoint hierarchy can be represented exactly when verified; overlapping decisions require an identified model/constraints or refusal. Original scope is not narrowed to disjoint audiences by this safe admission rule.
- Cross-provider objective: platform-reported credited outcomes are not automatically a common unique estimand. A typed explicit reported-credit surrogate may be offered only with clear definition and limits. A business decision to optimize that surrogate rather than verified outcomes is not inferred.
- Risk preference/targets/horizon/actual budgets: explicit scenario inputs, not arbitrary engineering defaults. No actual user scenario exists. These are not blockers to implementing and testing the general machinery with labeled synthetic cases.

No owner question is required to preserve exact tokens, use rational sums, withhold legacy exactness or implement the general typed contract. The choices above become questions only if an actual requested scenario or display rule cannot be implemented without selecting one. No solver route, UI default or new database constraint will silently settle them.

## Independent acceptance evidence to produce

- Arbitrary-length decimal add/subtract/multiply/divide compared with independent integer/rational reference arithmetic, including values above2^53 and source scales beyond4.
- Exact permutation invariance and partition conservation; preserved trailing source zeros; negative corrections only under explicit contract, no nonfinite/scientific/whitespace/float acceptance by accident.
- Integral versus fractional-credit validation; duplicate/missing and mixed unit/currency/window/population refusals.
- Nonterminating and zero-denominator ratios, source micros exact transforms, serialization/browser round-trip without Number coercion.
- Independent exact budget/bound conservation on final represented allocation. Approximate objective/KKT/global-gap guarantees separately tested against known cases, never called exact arithmetic merely because a tolerance passes.
- Legacy paths and new exact paths cannot share a misleading exactness badge. New pipeline must prove end-to-end use, not only utility-class tests.

## Typed target objective clarification

A target threshold does not select the secondary optimization direction. The new unified problem requires target_optimization=minimize_spend or maximize_response for every TARGET_* input, rather than adopting one silently. This is an explicit scenario parameter, not a standing owner preference. Existing legacy routes and frozen delivered artifacts are not retroactively rewritten.
