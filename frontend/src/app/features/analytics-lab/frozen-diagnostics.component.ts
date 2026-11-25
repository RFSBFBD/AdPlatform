import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-diagnostics',standalone:true,imports:[CommonModule],template:`<section><h4>Same-snapshot diagnostic evidence</h4>
@if(evidence()?.snapshot_id===snapshot() && evidence()?.diagnostics){
<p>Snapshot {{snapshot()}}. Descriptive directions are not a validated health verdict, statistical significance, creative fatigue, audience saturation or causal explanation.</p>
@if(matrixRows().length){<h5>Campaign health matrix: descriptive directions only</h5><p>No red/green health score or ranking. Arrows show exact operand direction across the archived adjacent windows, not whether a campaign is good or bad. Open a row below for the exact operands. Scroll horizontally to see all metrics on narrow screens.</p><div class="scroll"><table class="matrix"><thead><tr><th>Atomic entity</th>@for(metric of matrixMetrics;track metric){<th>{{metric}}</th>}<th>Decision health</th></tr></thead><tbody>@for(entry of matrixRows();track entry.id){<tr><th>{{entry.id}}</th>@for(metric of matrixMetrics;track metric){<td [attr.data-direction]="entry.directions[metric] || 'unavailable'">{{directionLabel(entry.directions[metric])}}</td>}<td>Insufficient Data</td></tr>}</tbody></table></div>}
@for(entry of evidence().diagnostics | keyvalue;track entry.key){<details><summary>{{entry.key}}: {{row(entry.value).status}}</summary>
<p>Health: {{row(entry.value).health}}. {{row(entry.value).reason || row(entry.value).health_reason}}</p>
@if(row(entry.value).baseline && row(entry.value).current){<p>Baseline {{row(entry.value).baseline.from}} through {{row(entry.value).baseline.through}}; current {{row(entry.value).current.from}} through {{row(entry.value).current.through}}. Equal adjacent windows, not a forecast. Excluded earliest dates: {{row(entry.value).excluded_earliest_days?.join(', ') || 'none'}}.</p>
<p>Spend, CPC, CPM and CPA use {{currency() || 'the archived billing currency'}}. CTR and CVR are ratios, not percentage-scaled values; ROAS is value per spend. Counts and values retain the archived outcome definition. Scroll horizontally for direction/change columns on narrow screens.</p>
<div class="scroll"><table><thead><tr><th>Metric</th><th>Exact baseline</th><th>Exact current</th><th>Direction</th><th>Exact relative change</th></tr></thead><tbody>@for(signal of row(entry.value).signals | keyvalue;track signal.key){<tr><th>{{signal.key}}</th><td>{{operand(row(entry.value).baseline.metrics[key(signal.key)])}}</td><td>{{operand(row(entry.value).current.metrics[key(signal.key)])}}</td><td>{{row(signal.value).direction || 'Unavailable'}}</td><td>{{fraction(row(signal.value).change_fraction)}}@if(row(signal.value).zero_baseline){ (zero baseline, fraction undefined)}</td></tr>}</tbody></table></div>
<p>Relative changes are fractions, not rounded percentages. Missing operands stay unavailable. {{row(entry.value).policy}}</p>
@for(limit of row(entry.value).limits;track limit){<p>{{limit}}</p>}
}
</details>}
}@else{<p>Unavailable: same-snapshot computed diagnostics required. No health judgment is invented.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}summary{cursor:pointer}details{margin:12px 0}.scroll{overflow:auto}.matrix td{text-align:center}.matrix [data-direction="up"],.matrix [data-direction="down"],.matrix [data-direction="unchanged"]{background:#eef2f8;color:#23344c}.matrix [data-direction="unavailable"]{color:#606b7a}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}`]})
export class FrozenDiagnosticsComponent{
 readonly matrixMetrics=['spend','ctr','cpc','cvr','cpa','roas'];
 matrixRows():Array<{id:string,directions:Record<string,string|null>}>{const e=this.evidence();if(!this.snapshot()||e?.snapshot_id!==this.snapshot()||!e.diagnostics||typeof e.diagnostics!=='object')return [];return Object.entries(e.diagnostics).map(([id,value])=>{const r=value as any;const directions:Record<string,string|null>={};for(const metric of this.matrixMetrics){const s=r?.signals?.[metric];directions[metric]=r?.status==='descriptive_signals_only'&&r?.baseline&&r?.current&&s?.status==='descriptive_comparison'&&['up','down','unchanged'].includes(s.direction)?s.direction:null;}return {id,directions};});}
 directionLabel(direction:string|null):string{return direction==='up'?'↑ Up':direction==='down'?'↓ Down':direction==='unchanged'?'→ Unchanged':'? Unavailable';}
 readonly evidence=input<any>(null);readonly snapshot=input('');readonly currency=input('');
 row(value:any):any{return value;}key(value:any):string{return String(value);}
 fraction(value:any):string{return value?.numerator!==undefined&&value?.denominator!==undefined?`${value.numerator}/${value.denominator}`:'Unavailable';}
 operand(value:any):string{return value?.decimal!==null&&value?.decimal!==undefined?String(value.decimal):this.fraction(value?.fraction);}
}
