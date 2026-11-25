import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-explanation',standalone:true,imports:[CommonModule],template:`<section><h4>Computed explanation references</h4><p>Fixed computed facts, not an external AI answer. Exact recorded values, approximate model responses and unavailable probabilities stay distinct. No campaign effect or spending approval.</p>
@if(explanation()?.snapshot_id===snapshot() && explanation()?.status==='computed_reference_facts'){
<details><summary>Inspect computed facts and their evidence pointers</summary><p>Each pointer addresses the archived six-block analyst input, not fresh data. No raw source row or request details are passed through these facts. Scroll horizontally for complete operands and references.</p>
<div class="scroll"><table><thead><tr><th>Fact</th><th>Type / precision</th><th>Computed value</th><th>Unit</th><th>Evidence pointer</th></tr></thead><tbody>@for(fact of explanation().facts;track fact.id){<tr><th>{{fact.label}}</th><td>{{fact.type}} / {{fact.precision}}</td><td>{{fact.status==='unavailable'?'Unavailable':display(fact.value)}}</td><td>{{fact.unit || 'not applicable'}}</td><td>{{fact.pointer}}</td></tr>}</tbody></table></div>
@for(limit of explanation().limits;track limit){<p>{{limit}}</p>}
</details>
}@else{<p>Unavailable: same-snapshot computed explanation references required. No narrative or probability invented.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}.scroll{overflow:auto}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}tbody th{white-space:normal;min-width:130px;max-width:180px;overflow-wrap:anywhere}summary{cursor:pointer}`]})
export class FrozenExplanationComponent{
 readonly explanation=input<any>(null);readonly snapshot=input('');
 display(value:any):string{return typeof value==='object'?JSON.stringify(value):String(value);}
}
