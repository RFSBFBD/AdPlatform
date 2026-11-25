import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';

/** Frozen checked evidence only. Dots deliberately do not assert interpolation or causal effects. */
@Component({selector:'app-frozen-model-plots',standalone:true,imports:[CommonModule],template:`
<section><h4>Frozen response and marginal samples</h4>
@if(plots()?.status==='available_data' && plots()?.snapshot_id===snapshot()){
<p>SCENARIO, not actual delivery or causal return. Snapshot {{snapshot()}}. Dots are independent samples, not a fitted line between samples. Positions and axes use approximate numerical display; the table preserves exact spend strings.</p>
<p>{{plots().limits}}</p>
@for(entry of plots().curves | keyvalue;track entry.key){
@if(valid(entry.value)){<details><summary>{{entry.key}}: {{curve(entry.value).model}} samples</summary>
<p>{{curve(entry.value).period_days}}-day period. {{curve(entry.value).period_assumption}}. Sampling policy {{curve(entry.value).sample_policy}}. Response unit {{curve(entry.value).response_unit}}; marginal unit {{curve(entry.value).marginal_unit}}. No audience saturation threshold or uncertainty interval is inferred. Scroll the charts and table horizontally on narrow screens.</p>
@for(metric of metrics;track metric.key){<h5>{{metric.label}}</h5><div class="scroll"><svg viewBox="0 0 640 260" role="img" [attr.aria-label]="metric.label+' sampled against exact budget operands for '+label(entry.key)">
<line x1="85" y1="205" x2="600" y2="205" stroke="#94a3b8"/><line x1="85" y1="25" x2="85" y2="205" stroke="#94a3b8"/>
<text x="78" y="32" text-anchor="end">{{axisLabel(maximum(entry.value,metric.key))}}</text><text x="78" y="205" text-anchor="end">0</text>
@for(point of curve(entry.value).points;track $index){<circle [attr.cx]="x(point.spend_exact,entry.value)" [attr.cy]="y(point[metric.key],entry.value,metric.key)" r="4" fill="#4f46e5"><title>Spend {{point.spend_exact}}, {{metric.label}} {{point[metric.key]}}</title></circle>}
<text x="85" y="227">{{axisLabel(minSpend(entry.value))}}</text><text x="600" y="227" text-anchor="end">{{axisLabel(maxSpend(entry.value))}}</text><text x="330" y="250" text-anchor="middle">Period budget, approximate axis. Exact operands below.</text></svg></div>}
<div class="scroll"><table><thead><tr><th>Exact period spend</th><th>Approximate response</th><th>Approximate marginal</th><th>Derivative implementation check</th></tr></thead><tbody>@for(point of curve(entry.value).points;track $index){<tr><td>{{point.spend_exact}}</td><td>{{point.response_approximate}}</td><td>{{point.marginal_approximate}}</td><td>{{point.marginal_check?.method || 'Unavailable'}}</td></tr>}</tbody></table></div></details>
}@else{<p role="status">{{entry.key}}: plot withheld, invalid or mismatched frozen operands.</p>}}
}@else{<p>Unavailable: {{plots()?.reason || 'admitted same-snapshot frozen model evidence required'}}. No example model is substituted.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}summary{cursor:pointer}details{margin:14px 0}.scroll{overflow:auto;max-width:100%}svg{display:block;width:100%;min-width:570px}text{font:12px sans-serif;fill:var(--text-primary,#475569)}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}`]})
export class FrozenModelPlotsComponent{
 readonly plots=input<any>(null);readonly snapshot=input('');
 readonly metrics=[{key:'response_approximate',label:'Modeled response'},{key:'marginal_approximate',label:'Modeled marginal response'}];
 label(value:any):string{return String(value);}
 curve(value:any):any{return value;}
 valid(value:any):boolean{return value?.source_snapshot_id===this.snapshot()&&Array.isArray(value?.points)&&value.points.length>0&&value.points.length<=500&&value.points.every((p:any)=>typeof p.spend_exact==='string'&&/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(p.spend_exact)&&Number.isFinite(Number(p.spend_exact))&&Number(p.spend_exact)<=1e12&&this.metrics.every(m=>typeof p[m.key]==='number'&&Number.isFinite(p[m.key])&&p[m.key]>=0));}
 minSpend(value:any):number{return Math.min(...value.points.map((p:any)=>Number(p.spend_exact)));}
 maxSpend(value:any):number{return Math.max(...value.points.map((p:any)=>Number(p.spend_exact)));}
 axisLabel(value:number):string{return value!==0&&(Math.abs(value)<0.0001||Math.abs(value)>=1e7)?value.toExponential(4):String(Number(value.toPrecision(6)));}
 maximum(value:any,key:string):number{return Math.max(...value.points.map((p:any)=>p[key]))||1;}
 x(spend:string,value:any):number{const min=this.minSpend(value),range=this.maxSpend(value)-min;return range===0?342.5:85+515*(Number(spend)-min)/range;}
 y(response:number,value:any,key:string):number{return 205-175*(response/this.maximum(value,key));}
}
