#!/usr/bin/env python3
"""Separable concave maximization proposal. PHP independently checks feasibility and tangent bound."""
import json
import math
import sys


def solve(data):
    import numpy as np
    import scipy
    from scipy.optimize import linprog, minimize, Bounds, LinearConstraint
    variables = data['variables']
    if not 1 <= len(variables) <= 40 or len(data['groups']) > 100:
        raise ValueError('resource limit')
    ids = [v['id'] for v in variables]
    lo = np.array([v['minimum'] for v in variables]); hi = np.array([v['maximum'] for v in variables])
    matrix = [np.ones(len(ids)), -np.ones(len(ids))]
    rhs = [data['budget_cap'], -data['minimum_spend']]
    for group in data['groups']:
        row = np.array([1. if x in group['members'] else 0. for x in ids])
        matrix.extend([row, -row]); rhs.extend([group['maximum'], -group['minimum']])
    matrix, rhs = np.array(matrix), np.array(rhs)
    initial = linprog(np.zeros(len(ids)), A_ub=matrix, b_ub=rhs, bounds=list(zip(lo, hi)), method='highs')
    if not initial.success:
        return {'status': 'linear_domain_infeasible_or_solver_failed'}
    def curve(v, x):
        p = v['parameters']; a, b = p['a'], p['b']; scale = v['scale']
        if v['name'] == 'linear':
            return scale * a * x, scale * a
        if v['name'] == 'log':
            return scale * a * math.log1p(b * x), scale * a * b / (1 + b * x)
        if v['name'] == 'saturation':
            return scale * a * -math.expm1(-b * x), scale * a * b * math.exp(-b * x)
        if v['name'] == 'quadratic_spline':
            knot = p['knot']
            if x <= knot:
                return scale * a * (x - (1-b)*x*x/(2*knot)), scale*a*(1-(1-b)*x/knot)
            return scale*a*(knot*(1+b)/2+b*(x-knot)), scale*a*b
        if v['name'] == 'power':
            if x <= 0:
                raise ValueError('power domain needs positive lower support')
            return scale * a * x ** b, scale * a * b * x ** (b - 1)
        raise ValueError('unsupported smooth concave family')
    def objective(x):
        return -sum(curve(v, t)[0] for v, t in zip(variables, x))
    def jac(x):
        return -np.array([curve(v, t)[1] for v, t in zip(variables, x)])
    floor = max(data['minimum_response'], data['target_response'])
    # Strictly interior proposal target, never a relaxed original acceptance threshold.
    inner_margin = 1e-10 * max(1., floor) if data.get('target_optimization') == 'minimize_spend' else 0.
    floor += inner_margin
    constraints = [LinearConstraint(matrix, -np.inf, rhs)]
    if floor > 0:
        constraints.append({'type': 'ineq', 'fun': lambda x: -objective(x)-floor, 'jac': lambda x: -jac(x)})
    ratio_target = data.get('ratio_target',0.)
    ratio_margin = 0.
    if ratio_target > 0:
        ratio_margin = 1e-10*max(1., floor)
        constraints.append({'type':'ineq','fun':lambda x:-objective(x)-ratio_target*float(np.sum(x))-ratio_margin,'jac':lambda x:-jac(x)-ratio_target})
    minimize_spend = data.get('target_optimization') == 'minimize_spend'
    ratio = data.get('objective') in ('MINIMIZE_CPA', 'MAXIMIZE_ROAS')
    parameter = 0.
    trace = []
    for iteration in range(60 if ratio else 1):
        solve_objective = (lambda x: float(np.sum(x))) if minimize_spend else (lambda x: objective(x)+parameter*float(np.sum(x)))
        solve_jac = (lambda x: np.ones(len(ids))) if minimize_spend else (lambda x: jac(x)+parameter)
        candidates = []
        for start in [initial.x, (lo + hi) / 2]:
            r = minimize(solve_objective, start, jac=solve_jac, method='SLSQP', bounds=Bounds(lo, hi),
                         constraints=constraints, options={'maxiter': 1000, 'ftol': 1e-11})
            if r.success and np.all(np.isfinite(r.x)) and np.all(matrix @ r.x <= rhs + 1e-7):
                candidates.append(r)
        if not candidates:
            return {'status': 'proposal_not_converged'}
        r = min(candidates, key=lambda x: x.fun)
        spent = float(np.sum(r.x)); response = -objective(r.x)
        trace.append({'parameter': parameter, 'response': response, 'spend': spent})
        if not ratio or abs(response-parameter*spent) <= 1e-10*max(1.,response):
            break
        if spent <= 0:
            return {'status': 'undefined_ratio'}
        parameter = response/spent
        initial.x = r.x
    else:
        return {'status': 'ratio_proposal_not_converged'}
    return {'status': 'proposed', 'version': 'typed-concave-proposal-v1', 'solver': 'scipy.optimize.minimize/SLSQP',
            'scipy_version': scipy.__version__, 'allocation': dict(zip(ids, r.x.tolist())), 'iterations': int(r.nit),
            'fractional_trace': trace, 'proposal_ratio_target_inner_margin': ratio_margin, 'proposal_response_inner_margin': inner_margin, 'modeled_response': -objective(r.x), 'minimized_spend': float(np.sum(r.x)) if minimize_spend else None, 'guarantee': 'proposal_only_requires_independent_tangent_upper_bound'}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4000001)
        if len(raw) > 4000000:
            raise ValueError('input limit')
        print(json.dumps(solve(json.loads(raw)), allow_nan=False, separators=(',', ':')))
    except ImportError:
        print(json.dumps({'status': 'solver_dependency_unavailable'}))
    except Exception:
        print(json.dumps({'status': 'solver_invalid_or_failed'}))
