#!/usr/bin/env python3
"""Local multiple-choice model solver. No network, files, source access or campaign effects."""
import json
import math
import sys


def solve(data):
    import numpy as np
    import scipy
    from scipy.optimize import milp, Bounds, LinearConstraint
    from scipy.sparse import lil_matrix

    menus = data['menus']
    groups = data['groups']
    objective = data['objective']
    count = sum(len(m['choices']) for m in menus)
    if not 1 <= len(menus) <= 100 or not 1 <= count <= 10000 or len(groups) > 100:
        raise ValueError('menu resource limit')
    spend, response, owners = [], [], []
    for i, menu in enumerate(menus):
        for choice in menu['choices']:
            x, y = choice['spend'], choice['response']
            if not all(isinstance(v, (float, int)) and math.isfinite(v) and v >= 0 for v in (x, y)):
                raise ValueError('invalid choice')
            spend.append(x)
            response.append(y)
            owners.append(i)
    spend, response = np.array(spend), np.array(response)
    # One point per atomic variable, exact represented money checked again by PHP.
    matrix = lil_matrix((len(menus) + 2 + len(groups) + int(objective.startswith('TARGET_')), count))
    lower, upper = [1.] * len(menus), [1.] * len(menus)
    for j, i in enumerate(owners):
        matrix[i, j] = 1.
    row = len(menus)
    matrix[row, :] = spend
    lower.append(data['minimum_spend']); upper.append(data['budget_cap']); row += 1
    matrix[row, :] = response
    lower.append(data['minimum_response']); upper.append(np.inf); row += 1
    for group in groups:
        for j, owner in enumerate(owners):
            if menus[owner]['id'] in group['members']:
                matrix[row, j] = spend[j]
        lower.append(group['minimum']); upper.append(group['maximum']); row += 1
    if objective.startswith('TARGET_'):
        target = data['target']
        if objective == 'TARGET_REVENUE':
            matrix[row, :] = response; lower.append(target); upper.append(np.inf)
        elif objective == 'TARGET_ROAS':
            matrix[row, :] = response - target * spend; lower.append(0.); upper.append(np.inf)
        elif objective == 'TARGET_CPA':
            matrix[row, :] = target * response - spend; lower.append(0.); upper.append(np.inf)
        else:
            raise ValueError('unsupported target')
    constraint = LinearConstraint(matrix.tocsc(), np.array(lower), np.array(upper))
    ratio = objective in ('MINIMIZE_CPA', 'MAXIMIZE_ROAS')
    parameter, trace = 0., []
    result = None
    for iteration in range(50 if ratio else 1):
        c = spend if data.get('target_optimization') == 'minimize_spend' else -(response - parameter * spend if ratio else response)
        result = milp(c, integrality=np.ones(count), bounds=Bounds(np.zeros(count), np.ones(count)),
                      constraints=constraint,
                      options={'time_limit': 5., 'node_limit': 100000, 'mip_rel_gap': 0., 'presolve': True})
        if result.status != 0 or not result.success or result.x is None:
            return {'status': 'not_solved', 'solver_status': int(result.status)}
        xs, ys = float(spend @ result.x), float(response @ result.x)
        if not all(math.isfinite(x) for x in (xs, ys)):
            raise ValueError('nonfinite result')
        residual = ys - parameter * xs
        trace.append({'spend': xs, 'response': ys, 'ratio_parameter': parameter,
                      'fractional_residual': residual, 'mip_gap': float(result.mip_gap),
                      'linear_dual_bound': float(result.mip_dual_bound)})
        if not ratio or abs(residual) <= 1e-9 * max(1., abs(ys)):
            break
        if xs <= 0:
            raise ValueError('undefined ratio')
        parameter = ys / xs
    else:
        return {'status': 'fractional_search_not_converged', 'trace': trace}
    indices = {}
    for owner, menu in enumerate(menus):
        columns = [j for j, item in enumerate(owners) if item == owner]
        chosen = [local for local, j in enumerate(columns) if result.x[j] > .5]
        if len(chosen) != 1:
            raise ValueError('invalid integral choice')
        indices[menu['id']] = chosen[0]
    return {'status': 'solved', 'solver': 'scipy.optimize.milp/HiGHS', 'scipy_version': scipy.__version__,
            'version': 'typed-finite-milp-v1', 'choices': indices, 'trace': trace,
            'guarantee': 'numerical_MILP_and_fractional_residual_only_not_exact_real_arithmetic'}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4000001)
        if len(raw) > 4000000:
            raise ValueError('input resource limit')
        print(json.dumps(solve(json.loads(raw)), allow_nan=False, separators=(',', ':')))
    except ImportError:
        print(json.dumps({'status': 'solver_dependency_unavailable'}))
    except Exception:
        print(json.dumps({'status': 'solver_invalid_or_failed'}))
