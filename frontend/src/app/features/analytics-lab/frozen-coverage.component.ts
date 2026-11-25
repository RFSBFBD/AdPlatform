import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-coverage',standalone:true,imports:[CommonModule],template:`<section><h4>Supplied calendar and missing operands</h4>
@if(coverage()?.snapshot_id===snapshot() && coverage()?.status==='audited_supplied_calendar_only'){
<p>{{coverage().window}} ({{coverage().timezone}}). {{coverage().returned_cells}} returned entity-days of {{coverage().expected_cells_within_supplied_entities}} expected cells within supplied entities. Missing {{coverage().missing_cells_within_supplied_entities}}. Supplied grid complete: {{coverage().supplied_grid_complete}}. Provider inventory completeness: unknown, not inferred.</p>
<p>{{coverage().limits}}</p><details><summary>Entity-day gaps and unknown metric operands</summary><p>Missing metric counts below apply only to returned rows, not omitted days. Scroll horizontally for all exact counts.</p>
<div class="scroll"><table><thead><tr><th>Entity</th><th>Returned / declared days</th><th>Missing days</th><th>Missing metric operands in returned rows</th></tr></thead><tbody>@for(entry of coverage().entities | keyvalue;track entry.key){<tr><td>{{entry.key}}</td><td>{{row(entry.value).returned_days}} / {{row(entry.value).declared_window_days}}</td><td>{{row(entry.value).missing_entity_days}}: {{row(entry.value).missing_day_examples.join(', ') || 'none'}}@if(row(entry.value).missing_day_examples_truncated){ (examples only)}</td><td>{{row(entry.value).missing_operands_within_returned_rows | json}}</td></tr>}</tbody></table></div></details>
}@else{<p>Unavailable: {{coverage()?.reason || 'same-snapshot supplied-calendar audit required'}}. No omitted contribution assumed zero.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}.scroll{overflow:auto}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}summary{cursor:pointer}`]})
export class FrozenCoverageComponent{
 readonly coverage=input<any>(null);readonly snapshot=input('');row(value:any):any{return value;}
}
