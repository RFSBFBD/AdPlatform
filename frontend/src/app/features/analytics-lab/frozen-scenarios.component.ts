import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-scenarios',standalone:true,imports:[CommonModule],template:`<section><h4>Frozen scenario comparison</h4>
<p>ACTUAL recorded totals remain separate above. These are evaluations under frozen models, not observed outcomes, causal lift, probabilities, spending permission or guaranteed improvement.</p>
@if(incidence()?.snapshot_id===snapshot() && incidence()?.status==='computed_atomic_membership'){
<details><summary>Atomic budget membership and derived platform totals</summary><p>Atomic level {{incidence().atomic_entity_level}}. Platform totals partition atomic budgets and are not independent extra spend. Constraint groups may overlap; never sum group totals into a portfolio or infer audience deduplication.</p>
@for(item of incidence().scenarios | keyvalue;track item.key){<p>{{item.key}}: {{row(item.value).status}}</p>@if(row(item.value).status==='derived_checked_atomic_rollups'){<div class="scroll"><table><thead><tr><th>Declared platform</th><th>Atomic members</th><th>Exact derived spend ({{currency()}})</th></tr></thead><tbody>@for(entry of row(item.value).platform_spend_exact | keyvalue;track entry.key){<tr><td>{{entry.key}}</td><td>{{incidence().platform_membership[key(entry.key)].join(', ')}}</td><td>{{entry.value}}</td></tr>}</tbody></table></div><p>Exact partition sum {{row(item.value).platform_partition_sum_exact}} {{currency()}}. Matches independently checked atomic total: {{row(item.value).matches_checked_total}}.</p>}}
@for(limit of incidence().limits;track limit){<p>{{limit}}</p>}
</details>}
@if(chart()?.snapshot_id===snapshot()){@for(reference of chart()?.reference_availability | keyvalue;track reference.key){<p>{{reference.key}}: {{row(reference.value).status}}. {{row(reference.value).reason}} {{row(reference.value).limits}}</p>}}
@if(chart()?.snapshot_id===snapshot() && chart()?.modeled_scenarios?.length){
<div class="scroll"><table><thead><tr><th>Scenario</th><th>Independent check</th><th>Exact modeled spend ({{currency()}})</th><th>Approximate modeled response ({{responseUnit()}})</th></tr></thead><tbody>@for(scenario of chart().modeled_scenarios;track scenario.name){<tr><th>{{scenario.name}}</th><td>{{scenario.check_status}}</td><td>{{scenario.spend_exact ?? 'Unavailable'}}</td><td>{{scenario.check_status==='checked' ? (scenario.response_approximate ?? 'Unavailable') : 'Withheld: scenario rejected'}}</td></tr>}</tbody></table></div>
<p>Exact spend is accounting over proposed allocations, not an actual billed amount. A rejected no-change/custom allocation does not become a usable baseline. Scroll tables horizontally on narrow screens.</p>
@for(entry of chart().modeled_scenarios;track entry.name){<details><summary>{{entry.name}} allocation operands, {{entry.check_status}}</summary><p>{{entry.reference_policy}} {{entry.reference_limits}}</p><div class="scroll"><table><thead><tr><th>Variable</th><th>Exact proposed budget ({{currency()}})</th></tr></thead><tbody>@for(allocation of entry.allocation | keyvalue;track allocation.key){<tr><td>{{allocation.key}}</td><td>{{allocation.value}}</td></tr>}</tbody></table></div></details>}
@if(chart().comparison?.snapshot_id===snapshot()){
<p>{{chart().comparison.limits}}</p>
@for(entry of chart().comparison.comparisons | keyvalue;track entry.key){<details><summary>Optimized versus {{entry.key}}: {{row(entry.value).status}}</summary>
@if(row(entry.value).status==='compared_frozen_model_scenarios'){
<p>{{row(entry.value).reference_policy}} {{row(entry.value).reference_limits}}</p><p>Same total modeled spend: {{row(entry.value).same_total_spend}}. Target policy {{row(entry.value).target_optimization || 'not applicable'}}. A minimum-spend target can reduce response deliberately.</p>
<div class="scroll"><table><thead><tr><th>Operand</th><th>Baseline</th><th>Optimized</th><th>Change</th><th>Relative fraction, not percent</th></tr></thead><tbody>
<tr><th>Exact spend ({{currency()}})</th><td>{{row(entry.value).baseline_spend_exact}}</td><td>{{row(entry.value).candidate_spend_exact}}</td><td>{{row(entry.value).spend_change_exact}}</td><td>{{fraction(row(entry.value).spend_change_fraction_exact)}}</td></tr>
<tr><th>Approximate response ({{responseUnit()}})</th><td>{{row(entry.value).baseline_response_approximate}}</td><td>{{row(entry.value).candidate_response_approximate}}</td><td>{{row(entry.value).response_change_approximate}}</td><td>{{row(entry.value).response_change_fraction_approximate ?? 'Undefined: zero baseline'}}</td></tr>
</tbody></table></div>
<p>Exact L1 budget movement {{row(entry.value).allocation_l1_change_exact}} {{currency()}}, not a stability probability. Unequal-spend comparisons are not silently normalized.</p>
}@else{<p>{{row(entry.value).reason}}. No baseline estimate substituted.</p>}
</details>}
}
}@else{<p>Unavailable: checked same-snapshot model scenarios required. Source abstention is not an optimization result.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}.scroll{overflow:auto}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}details{margin:12px 0}summary{cursor:pointer}`]})
export class FrozenScenariosComponent{
 readonly incidence=input<any>(null);readonly chart=input<any>(null);readonly snapshot=input('');readonly currency=input('');readonly responseUnit=input('');
 key(value:any):string{return String(value);}
 row(value:any):any{return value;}
 fraction(value:any):string{return value?.numerator!==undefined&&value?.denominator!==undefined?`${value.numerator}/${value.denominator}`:'Undefined: zero baseline';}
}
