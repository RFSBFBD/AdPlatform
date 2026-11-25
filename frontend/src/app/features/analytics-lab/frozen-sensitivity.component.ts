import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-sensitivity',standalone:true,imports:[CommonModule],template:`<section><h4>Frozen stress and budget sensitivity</h4>
<p>SCENARIO assumptions, not confidence, probability, causal response or campaign instructions. Fixed-allocation feasibility, reoptimization and fixed-rate identities are different calculations.</p>
@if(sensitivity()?.snapshot_id===snapshot()){
<p>Budget cap sensitivity: {{sensitivity().status}}. Frozen response models, no refit. All other constraints stay fixed.</p>
@for(item of sensitivity().scenarios;track $index){<details><summary>Budget cap multiplier {{item.scale}}: {{item.status}}</summary>
<p>Exact shocked cap {{item.budget_cap_exact ?? 'Unavailable'}} {{currency()}}. {{item.reason}}</p>
<p>Original optimized allocation under shocked constraints: {{item.fixed_allocation_check?.status || 'Unavailable'}}. This is a fixed-allocation feasibility check, not a new optimum.</p>
<p>Reoptimized allocation check: {{item.reoptimized?.check?.status || 'Unavailable'}}. Exact spend {{item.reoptimized?.check?.spend_exact ?? 'Unavailable'}} {{currency()}}. Approximate modeled response {{item.reoptimized?.check?.status==='checked' ? item.reoptimized.check.response_approximate : 'Withheld'}} {{responseUnit()}}.</p>
<p>Exact L1 budget movement {{item.allocation_l1_change_exact ?? 'Unavailable'}} {{currency()}}, not stability probability.</p>
@if(item.reoptimized?.check?.status==='checked'){<div class="scroll"><table><thead><tr><th>Variable</th><th>Exact reoptimized budget</th><th>Exact change</th></tr></thead><tbody>@for(entry of item.reoptimized.allocation | keyvalue;track entry.key){<tr><td>{{entry.key}}</td><td>{{entry.value}}</td><td>{{item.allocation_changes_exact[key(entry.key)] ?? 'Unavailable'}}</td></tr>}</tbody></table></div>}
</details>}
@for(limit of sensitivity().limits;track limit){<p>{{limit}}</p>}
}@else{<p>Budget sensitivity unavailable or not requested for this frozen snapshot.</p>}
@if(primitive()?.snapshot_id===snapshot()){
<p>Fixed-rate primitive scenarios: {{primitive().status}}. Rate estimator {{primitive().rate_estimator}}. No reoptimization or response-curve modification. Complete supplied calendar is not source/tracking completeness.</p>
@for(item of primitive().scenarios;track item.index){<details><summary>Primitive case {{item.index}}: {{item.result.status}}</summary>
@if(item.result.status==='evaluated_assumption_graph' && item.result.snapshot_id===snapshot()){
<p>Assumption {{item.result.assumption}}. Multipliers {{item.result.shock_multipliers | json}}. Derived CPC/CPA/ROAS are not independent controls. Fractional credits are not unique people or guaranteed outcomes.</p>
<p>Budget, CPM, CPC and CPA use {{currency()}}; value uses the declared outcome value, CTR/CVR/ROAS are unscaled ratios. All displayed operands are exact rational fractions under assumptions, not rounded settlement amounts. Scroll horizontally on narrow screens.</p>
<div class="scroll"><table><thead><tr><th>Primitive / derived metric</th><th>Exact baseline fraction</th><th>Exact shocked fraction</th></tr></thead><tbody>@for(entry of item.result.baseline | keyvalue;track entry.key){<tr><th>{{entry.key}}</th><td>{{fraction(entry.value)}}</td><td>{{fraction(item.result.changed[key(entry.key)])}}</td></tr>}</tbody></table></div>
}@else{<p>{{item.result.reason || 'Same-snapshot primitive result required'}}. No substitute scenario.</p>}
</details>}
@for(limit of primitive().limits;track limit){<p>{{limit}}</p>}
}@else{<p>Primitive scenarios unavailable or not requested. {{primitive()?.reason}}</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}.scroll{overflow:auto}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}details{margin:12px 0}summary{cursor:pointer}`]})
export class FrozenSensitivityComponent{
 readonly sensitivity=input<any>(null);readonly primitive=input<any>(null);readonly snapshot=input('');readonly currency=input('');readonly responseUnit=input('');
 key(value:any):string{return String(value);}
 fraction(value:any):string{return value?.numerator!==undefined&&value?.denominator!==undefined?`${value.numerator}/${value.denominator}`:'Unavailable / undefined denominator';}
}
