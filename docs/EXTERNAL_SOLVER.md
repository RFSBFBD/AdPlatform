# Optional discrete budget-menu MILP

This is an implemented, tested external solver route, not an LP/MILP label on the custom water-filling code. `backend/solver/discrete_milp.py` calls `scipy.optimize.milp`, which wraps HiGHS. Official contract checked2026-11-23:
https://docs.scipy.org/doc/scipy/reference/generated/scipy.optimize.milp.html

## Exact problem being solved

Each fitted entity has a finite menu of candidate30-day spends. Menu includes zero (pause), positive multiples of the user-supplied step within bounds, the positive lower bound and upper bound. Each choice value is evaluated on the same fitted30-day response curve. Binary z_ik selects exactly one choice per entity:

- maximize sum z_ik * fitted_response_i(spend_ik)
- one choice per entity
- explicit minimum_total_spend <= sum selected spends <= budget cap
- number of positive-spend choices <= explicit maximum_active
- all z binary

In this separate mode the per-entity minimum is MINIMUM IF ACTIVE, not a mandatory allocation. The UI states this semantic difference and does not silently switch modes. MAXIMIZE_REVENUE, MAXIMIZE_CONVERSIONS and explicit MAXIMIZE_LEADS are supported; TARGET_REVENUE adds the separate minimum-spend menu problem below. TARGET_CPA/TARGET_ROAS add the linear portfolio ratio constraint described below. Unconstrained MAXIMIZE_ROAS/MINIMIZE_CPA remain rejected, not substituted. The user must choose mode, step and maximum active count. Zero does not pause a real campaign: all work remains a scenario.

An optimum is global only for this FINITE MENU. No continuous fitted-curve optimum or continuous approximation-error bound is claimed. Spending can stay below cap. Max100entities,101choices/entity,10solver seconds,100000nodes. Status0 plus success and zero reported MIP gap required; time-limited incumbents are not final allocations. PHP independently checks every selected menu index, budget/minimum/active count, objective and gap. Python is a deployment-owned absolute executable from config, never a client-supplied command; input passes through stdin with an argument-list process, no shell.

## Optional runtime

Default `OR_SOLVER_PYTHON` is empty: selecting discrete mode returns solver_unavailable, with NO fallback to continuous. Install Python and the pinned `backend/solver/requirements.txt` packages in an isolated environment, then set OR_SOLVER_PYTHON to its absolute Python executable. Local verified versions Python3.11, SciPy1.15.3, NumPy2.2.6. Native container runtime does not yet install these automatically. No Docker build or production deployment was tested. No network service is called by the solver.

Archives preserve source rows, curves, options and original result/certificate including SciPy version. Replay uses the deployment's currently installed solver and states that version changes can alter tied allocations. Future environment retention is not automated. Comparing a replay allocation is not proof of unique optimality in tied menus.

## Verification

Fixture suite compares a three-entity problem against exhaustive menu enumeration, includes minimum-if-active and activation-count constraints, rejects infeasible states and only accepts status0 (time-limited status is refused by contract), checks missing dependency and menu cap, and exercises HTTP archive/replay after source changes without campaign mutations. These tests establish the specified finite problem, not real advertising lift.

## Piecewise model routing

Explicit discrete mode adds a concave two-slope response candidate to the same chronological holdout/complexity/reduced-window stability pipeline. For train-only knots at spend quantiles25/50/75% and fixed second/first slope ratios0,.1,.25,.5,.75, the scale is least-squares fitted. Full-data refit follows model selection. Candidate is `a * (min(x,k) + ratio*max(0,x-k))`, with nonnegative decreasing slopes, three model parameters. It is an observational engineering family, not a calibrated saturation threshold. Piecewise knots scale by30 for equal-daily-spend period conversion. The discrete menu evaluates it directly. Smooth water-filling never selects the family and its marginal-inverse method explicitly refuses kinked curves. The additional C1 quadratic-to-linear spline is described below. No general spline or kink-aware continuous KKT solver is claimed. Archived parameters include the knot and replay preserves it.

## Bounded concave C1 spline candidate

The same explicit discrete route also considers `quadratic_spline`: for x<=k,
`a*(x-(1-r)*x*x/(2*k))`; above k, `a*(k*(1+r)/2+r*(x-k))`.

Assumptions: zero response at zero spend, nonnegative decreasing marginal response, one quadratic segment and one linear tail. a>0, k>0, 0<=r<=1. Derivative is `a*(1-(1-r)*min(x/k,1))`, continuous at k, and second derivative is `-a*(1-r)/k` below k and zero above. This is a restricted quadratic spline, not a cubic smoothing spline or unrestricted flexible regression. It uses the same train-only quantile knots, ratio grid, closed-form scale least squares, three-parameter complexity penalty, chronological holdout threshold and reduced-window stability test as the hinge. Selection and full-data refit are deterministic. A validation win does not prove causality, future accuracy, a saturation threshold or a calibrated interval. Tail extrapolation remains flagged.

Period transformation preserves a/r and scales k/support by days, so F(B)=days*f(B/days). Only direct finite-menu evaluation is allowed; its marginal inverse explicitly refuses continuous water-filling even though the first derivative is continuous. No unverified extension of a smooth solver certificate. Archive/replay serializes the family and knot. Validation checks values, derivative continuity and monotonicity, period identity, family selection, continuous-route exclusion, invalid ratio rejection and HTTP archive/replay.

## Minimum menu spend for a reported-value target

TARGET_REVENUE in explicit discrete mode minimizes sum(selected spends), subject to sum(fitted reported value)>=the user target plus the same cap, total minimum spend, one binary choice/entity, maximum active and minimum-if-active menu semantics. The exact same30-day value fits/menu samples are used, including validated hinge/spline candidates. It is not a maximum-response objective and may choose less response if sufficient for the target at lower spend. The optimum/lower bound is only for finite menus; there is no continuous approximation bound, causal lift or guaranteed realized revenue. Infeasible or time-limited status returns no allocation, never a best effort target violation. Zero gap/status0 required. PHP independently checks target feasibility, chosen spend/objective consistency and solver lower spend bound, on top of existing menu/response/budget/active checks. Replay retains target and selected solver. Engine entity-2.4/model discrete-menu-2. Exhaustive enumeration of a three-entity fixture verifies minimum cost, plus unreachable target and HTTP archive/replay after deleting current history. No provider calls or campaign changes.

## Portfolio CPA/ROAS target constraints on menus

Explicit discrete TARGET_ROAS maximizes fitted reported value subject to sum(value-r*spend)>=0. TARGET_CPA maximizes fitted reported conversions subject to sum(conversions-spend/targetCPA)>=0. Since menu responses are constants per binary choice, these are linear constraints, not nonlinear ratio optimization. Explicit positive minimum total spend excludes the zero-spend vacuous solution. Same budget/menu/max-active/min-if-active limits apply. Response and ratio units differ by objective and are not substituted. PHP recomputes the portfolio ratio from selected actual menu operands; unreachable targets return no allocation. Exact global scope remains finite menus only. Unconstrained ratio maximization/minimization and robust ratio MILP are not implemented. Engine entity-2.5/discrete-menu-3. Three-entity exhaustive enumeration and HTTP CPA/ROAS archive/replay after source deletion verify this bounded extension.

## Separate independent-scale box-robust menu scenario

When MAXIMIZE_REVENUE uses discrete mode and explicit robust_bounds, the separate robust scenario solves the same sampled spends and activation constraints with each entity's response scaled by(1-u_i). Nonnegative separable responses attain their joint minimum at independent lower box bounds, so this is an exact maximin counterpart ON THE FINITE MENUS. Not a general uncertainty-set MILP or robust ratio/target problem. Worst/nominal/best values are recomputed on the chosen robust allocation. User-entered bounds are not statistical confidence or probabilities, and do not address shape/timing/attribution/population error. Default unavailable dependency fails closed. Archive preserves the result. Explicit scenario=robust now re-executes successful archived entity independent-scale robust scenarios separately from nominal base replay, without current metrics or refitting. Two-entity exhaustive enumeration with max-active1 and a hinge family verifies the result. Engine entity2.6.

Runtime certificate acceptance additionally requires the reviewed model version discrete-menu-3, expected solver identity, parseable SciPy version, finite nonnegative MIP gap and finite primal/dual agreement for the selected objective. Response maximization checks the response upper bound; target minimum-spend checks the spend lower bound. Missing/nonfinite/wrong-version claims fail closed. These checks do not turn a runtime claim into an independently proven mathematical certificate; they supplement exact PHP menu feasibility and enumeration regression tests.
