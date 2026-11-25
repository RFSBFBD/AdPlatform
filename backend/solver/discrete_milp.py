#!/usr/bin/env python3
"""Versioned multiple-choice budget-menu MILP, stdin/stdout JSON, no network or files."""
import json, sys, math
VERSION = 'discrete-menu-3'
def run(payload):
    import numpy as np
    import scipy
    from scipy.optimize import milp, Bounds, LinearConstraint
    from scipy.sparse import lil_matrix
    menus=payload['menus']; budget=payload['budget']; minimum=payload['minimum_spend']; maximum_active=payload['maximum_active']
    if not 1<=len(menus)<=100 or not all(math.isfinite(x) for x in [budget,minimum]) or not 0<=minimum<=budget or not 1<=maximum_active<=len(menus):
        raise ValueError('Invalid menu scope or constraints')
    target=payload.get('target_response')
    if target is not None and (not isinstance(target,(int,float)) or not math.isfinite(target) or target<=0):raise ValueError('Invalid target response')
    ratio=payload.get('minimum_response_per_spend')
    if ratio is not None and (not isinstance(ratio,(int,float)) or not math.isfinite(ratio) or ratio<=0 or target is not None or minimum<=0):raise ValueError('Invalid ratio constraint')
    n=sum(len(m['choices']) for m in menus)
    if n>10100 or any(not 1<=len(m['choices'])<=101 for m in menus):raise ValueError('Local menu limit exceeded')
    A=lil_matrix((len(menus)+2+int(target is not None or ratio is not None),n)); c=[]; col=0
    for i,menu in enumerate(menus):
        for choice in menu['choices']:
            spend=choice['spend']; response=choice['response']
            if not all(isinstance(x,(int,float)) and math.isfinite(x) and x>=0 for x in [spend,response]):raise ValueError('Invalid menu values')
            A[i,col]=1; A[len(menus),col]=spend; A[len(menus)+1,col]=int(spend>0); c.append(spend if target is not None else -response)
            if target is not None:A[len(menus)+2,col]=response
            if ratio is not None:A[len(menus)+2,col]=response-ratio*spend
            col+=1
    result=milp(c,integrality=np.ones(n),bounds=Bounds(np.zeros(n),np.ones(n)),constraints=LinearConstraint(A.tocsc(),[1]*len(menus)+[minimum,0]+([target] if target is not None else ([0] if ratio is not None else [])),[1]*len(menus)+[budget,maximum_active]+([np.inf] if target is not None or ratio is not None else [])),options={'time_limit':10.,'node_limit':100000,'mip_rel_gap':0.0,'presolve':True})
    base={'solver':'scipy.optimize.milp/HiGHS','scipy_version':scipy.__version__,'model_version':VERSION,'solver_status':int(result.status)}
    if not result.success or result.status!=0 or result.x is None:return base|{'status':'not_solved','reason':'Solver did not return an optimal menu solution. No incumbent treated as final.'}
    choices={}; col=0
    for menu in menus:
        selected=[i for i,_ in enumerate(menu['choices']) if result.x[col+i]>.5]
        if len(selected)!=1:raise ValueError('Invalid integral choice')
        choices[menu['key']]=selected[0];col+=len(menu['choices'])
    if target is not None:
        spend=sum(m['choices'][choices[m['key']]]['spend'] for m in menus);response=sum(m['choices'][choices[m['key']]]['response'] for m in menus)
        return base|{'status':'ok','choices':choices,'mip_gap':float(getattr(result,'mip_gap',0) or 0),'objective_response':response,'objective_spend':spend,'dual_spend_bound':float(getattr(result,'mip_dual_bound',result.fun))}
    return base|{'status':'ok','choices':choices,'mip_gap':float(getattr(result,'mip_gap',0) or 0),'objective_response':float(-result.fun),'dual_response_bound':float(-getattr(result,'mip_dual_bound',result.fun))}
if __name__=='__main__':
    try:
        raw=sys.stdin.buffer.read(4000001)
        if len(raw)>4000000:raise ValueError('Input too large')
        print(json.dumps(run(json.loads(raw)),allow_nan=False,separators=(',',':')))
    except ImportError:
        print(json.dumps({'status':'unavailable','reason':'SciPy/HiGHS runtime dependency is not installed.'}))
    except Exception:
        print(json.dumps({'status':'invalid_or_failed','reason':'Invalid input or solver execution failed. No allocation.'}))
