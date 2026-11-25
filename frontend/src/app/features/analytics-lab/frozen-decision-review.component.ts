import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-decision-review',standalone:true,imports:[CommonModule],template:`
<section><h4>Structured simulation review</h4>
@if(review()?.snapshot_id===snapshot() && review()?.status==='computed_simulation_review'){
<p>Decision state {{review().decision_state}}. {{review().decision_state_reason}}. This is a model review queue, not a recommendation to change a campaign.</p>
<p>Objective {{review().objective}}, target policy {{review().target_optimization || 'not applicable'}}. {{review().candidate_count}} changed allocation candidates in declared variable order, not priority order. No change is not automatically a healthy verdict.</p>
@for(item of review().items;track item.id){<details><summary>{{item.entity.platform}} / {{item.entity.entity_level}} / {{item.entity.entity_id}}: simulation budget difference</summary>
<p>Model {{item.model.name}} / {{item.model.version || 'version unavailable'}}. Confidence probability, severity and priority are unavailable. Action {{item.action.type}}, no operational approval.</p>
<div class="scroll"><table><thead><tr><th>Operand</th><th>Value</th><th>Type</th></tr></thead><tbody>
<tr><th>Recorded spend ({{review().currency}})</th><td>{{item.observed.value_exact ?? 'Unavailable'}}</td><td>ACTUAL</td></tr>
<tr><th>No-change budget ({{review().currency}})</th><td>{{item.baseline.value_exact}}</td><td>SCENARIO exact budget</td></tr>
<tr><th>Proposed budget ({{review().currency}})</th><td>{{item.proposed.value_exact}}</td><td>SCENARIO exact budget</td></tr>
<tr><th>Budget change ({{review().currency}})</th><td>{{item.budget_change_exact}}</td><td>Exact difference</td></tr>
<tr><th>Relative change, not percent</th><td>{{fraction(item.budget_change_fraction_exact)}}</td><td>Exact fraction; undefined at zero baseline</td></tr>
<tr><th>No-change modeled response ({{item.expected_impact.unit}})</th><td>{{item.expected_impact.baseline_response_approximate ?? 'Unavailable'}}</td><td>SCENARIO approximate</td></tr>
<tr><th>Proposed modeled response ({{item.expected_impact.unit}})</th><td>{{item.expected_impact.proposed_response_approximate ?? 'Unavailable'}}</td><td>SCENARIO approximate</td></tr>
<tr><th>Modeled response change ({{item.expected_impact.unit}})</th><td>{{item.expected_impact.change_approximate ?? 'Unavailable'}}</td><td>Not causal lift or a guaranteed improvement</td></tr>
</tbody></table></div>
<p>Evidence {{item.evidence.allocation_pointer}}, independent check {{item.evidence.independent_check_pointer}}, certificate {{item.evidence.certificate_pointer}}. These address this frozen snapshot.</p>
</details>}
@for(limit of review().limits;track limit){<p>{{limit}}</p>}
}@else{<p>Unavailable: {{review()?.reason || 'checked same-snapshot no-change and optimized evidence required'}}. No budget recommendation, priority or probability is invented.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}details{margin:12px 0}summary{cursor:pointer}.scroll{overflow:auto;max-width:100%}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}`]})
export class FrozenDecisionReviewComponent{readonly review=input<any>(null);readonly snapshot=input('');fraction(v:any):string{return v?.denominator?`${v.numerator} / ${v.denominator}`:'Unavailable: zero baseline';}}
