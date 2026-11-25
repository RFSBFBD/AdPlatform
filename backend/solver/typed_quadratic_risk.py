#!/usr/bin/env python3
"""Proposal and tangent LP duals only. Exact PSD/objective/bound checks are separate."""
import json, sys

def solve(d):
    import numpy as np
    import scipy
    from scipy.optimize import linprog, minimize, Bounds, LinearConstraint
    v=d['variables'];n=len(v);ids=[x['id'] for x in v]
    if not 1<=n<=40:raise ValueError('dimension')
    a=np.array(d['slopes']);q=np.array(d['covariance']);lam=d['lambda']
    lo=np.array([x['minimum'] for x in v]);hi=np.array([x['maximum'] for x in v])
    rows=[np.ones(n),-np.ones(n),np.zeros(n)];rhs=[d['budget_cap'],-d['minimum_spend'],0.]
    for g in d['groups']:
        row=np.array([1. if x in g['members'] else 0. for x in ids]);rows.extend([row,-row]);rhs.extend([g['maximum'],-g['minimum']])
    rows.append(-a);rhs.append(-d['minimum_response']);rows=np.array(rows);rhs=np.array(rhs)
    initial=linprog(np.zeros(n),A_ub=rows,b_ub=rhs,bounds=list(zip(lo,hi)),method='highs')
    if not initial.success:return {'status':'quadratic_domain_not_solved'}
    f=lambda x:float(-a@x+lam*x@q@x)
    jac=lambda x:-a+2*lam*q@x
    r=minimize(f,initial.x,jac=jac,method='SLSQP',bounds=Bounds(lo,hi),constraints=[LinearConstraint(rows,-np.inf,rhs)],options={'ftol':1e-12,'maxiter':1000})
    if not r.success:return {'status':'quadratic_proposal_not_converged'}
    return {'status':'proposed','allocation':dict(zip(ids,r.x.tolist())),'scipy_version':scipy.__version__}
if __name__=='__main__':
    try:
        raw=sys.stdin.buffer.read(4000001)
        if len(raw)>4000000:raise ValueError('input')
        print(json.dumps(solve(json.loads(raw)),allow_nan=False))
    except Exception:print(json.dumps({'status':'quadratic_solver_failed'}))
