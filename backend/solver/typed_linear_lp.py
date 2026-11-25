#!/usr/bin/env python3
"""Typed continuous linear-response allocation. No network or account effects."""
import json
import math
import sys


def solve(data):
    import numpy as np
    import scipy
    from scipy.optimize import linprog
    variables = data['variables']
    n = len(variables)
    if not 1 <= n <= 100 or len(data['groups']) > 100:
        raise ValueError('resource limit')
    ids = [v['id'] for v in variables]
    slopes = np.array([v['slope'] for v in variables])
    if not np.all(np.isfinite(slopes)) or np.any(slopes < 0) or len(set(ids)) != n:
        raise ValueError('invalid slopes/identity')
    bounds = [(v['minimum'], v['maximum']) for v in variables]
    matrix, rhs, labels = [], [], []
    def inequality(row, bound, label):
        matrix.append(row); rhs.append(bound); labels.append(label)
    inequality(np.ones(n), data['budget_cap'], 'budget_cap')
    inequality(-np.ones(n), -data['minimum_spend'], 'minimum_spend')
    inequality(-slopes, -data['minimum_response'], 'minimum_response')
    for group in data['groups']:
        row = np.array([1. if x in group['members'] else 0. for x in ids])
        inequality(row, group['maximum'], 'group_max:' + group['id'])
        inequality(-row, -group['minimum'], 'group_min:' + group['id'])
    objective = data['objective']
    target = data['target']
    if objective == 'TARGET_REVENUE':
        inequality(-slopes, -target, 'target_response')
    elif objective == 'TARGET_CPA':
        inequality(np.ones(n) - target * slopes, target*data.get('response_intercept',0.), 'target_cpa')
    elif objective == 'TARGET_ROAS':
        inequality(target * np.ones(n) - slopes, data.get('response_intercept',0.), 'target_roas')
    for extra in data.get('extra_inequalities', []):
        inequality(np.array(extra['row']), extra['rhs'], 'extra_necessary_constraint')
    matrix, rhs = np.array(matrix), np.array(rhs)
    parameter = float(data.get('fixed_ratio_parameter', 0.))
    ratio = objective in ('MINIMIZE_CPA', 'MAXIMIZE_ROAS')
    trace = []
    for iteration in range(80 if ratio else 1):
        objective_slopes = np.array(data.get('objective_slopes', slopes))
        cost = np.ones(n) if data.get('target_optimization') == 'minimize_spend' else -(objective_slopes - parameter)
        result = linprog(cost, A_ub=matrix, b_ub=rhs, bounds=bounds, method='highs',
                         options={'dual_feasibility_tolerance': 1e-9, 'primal_feasibility_tolerance': 1e-9})
        if not result.success:
            return {'status': 'infeasible_or_solver_not_converged', 'solver_status': int(result.status)}
        spent = float(np.sum(result.x)); response = float(slopes @ result.x)
        dual = float(rhs @ result.ineqlin.marginals)
        dual += sum(lo * a + hi * b for (lo, hi), a, b in zip(bounds, result.lower.marginals, result.upper.marginals))
        residual = response - parameter * spent
        trace.append({'cost': cost.tolist(), 'dual_inequality': result.ineqlin.marginals.tolist(),
                      'dual_lower': result.lower.marginals.tolist(), 'dual_upper': result.upper.marginals.tolist(),
                      'primal_objective': float(result.fun), 'dual_objective': dual, 'spend': spent, 'response': response,
                      'ratio_parameter': parameter, 'fractional_residual': residual})
        if not ratio or abs(residual) <= 1e-9 * max(1., abs(response)):
            break
        if spent <= 0:
            raise ValueError('undefined ratio')
        parameter = response / spent
    else:
        return {'status': 'fractional_search_not_converged'}
    return {'status': 'solved', 'version': 'typed-linear-lp-v1', 'solver': 'scipy.optimize.linprog/HiGHS',
            'scipy_version': scipy.__version__, 'allocation': dict(zip(ids, result.x.tolist())),
            'matrix': matrix.tolist(), 'rhs': rhs.tolist(), 'constraint_labels': labels, 'trace': trace,
            'guarantee': 'numerical_LP_duality_and_fractional_residual_not_exact_real_optimum'}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4000001)
        if len(raw) > 4000000:
            raise ValueError('input size')
        print(json.dumps(solve(json.loads(raw)), allow_nan=False, separators=(',', ':')))
    except ImportError:
        print(json.dumps({'status': 'solver_dependency_unavailable'}))
    except Exception:
        print(json.dumps({'status': 'solver_invalid_or_failed'}))
