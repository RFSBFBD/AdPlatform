import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';

/** One metric per axis. Exact reported operands remain visible independently of plot projection. */
@Component({selector:'app-frozen-actual-trends',standalone:true,imports:[CommonModule],template:`
<section><h4>Frozen actual trends</h4>
<p>ACTUAL reported values in this snapshot, not forecasts or causal effects. Supplied-row calendar and source-population gates must pass. Missing values are never zero-filled. Each metric has a separate axis; dates are equally spaced only after calendar validation. Dots do not imply values between dates.</p>
@for(view of views;track view.key){
<details><summary>{{view.label}}</summary>
@if(available(view.key)){
@for(metric of chart(view.key).metrics;track metric){
@if(chart(view.key).metric_availability?.[metric]===true){
<h5>{{metric}} ({{unit(metric)}})</h5>
@if(plottable(view.key,metric)){
<p>Axes and dot positions are approximate display projections. Exact decimal or rational values are in the table.</p>
<div class="scroll"><svg viewBox="0 0 660 270" role="img" [attr.aria-label]="view.label+': '+metric+' reported actual values'">
<line x1="105" y1="200" x2="615" y2="200" stroke="#94a3b8"/><line x1="105" y1="25" x2="105" y2="200" stroke="#94a3b8"/>
<text x="98" y="32" text-anchor="end">{{axis(maximum(view.key,metric))}}</text><text x="98" y="200" text-anchor="end">0</text>
@for(point of chart(view.key).points;track point.date){<circle [attr.cx]="x($index,chart(view.key).points.length)" [attr.cy]="y(point.ratios[metric],view.key,metric)" r="4" fill="#0891b2"><title>{{point.date}}: {{exact(point.ratios[metric])}} {{unit(metric)}}</title></circle>}
<text x="105" y="224">{{chart(view.key).points[0].date}}</text><text x="615" y="244" text-anchor="end">{{chart(view.key).points[chart(view.key).points.length-1].date}}</text>
</svg></div>
}@else{<p>Plot projection unavailable. No rounded, infinite or underflowed projection is substituted; inspect exact values below.</p>}
<div class="scroll"><table><thead><tr><th>Reporting date</th><th>Exact {{metric}} ({{unit(metric)}})</th></tr></thead><tbody>
@for(point of chart(view.key).points;track point.date){<tr><td>{{point.date}}</td><td>{{exact(point.ratios[metric])}}</td></tr>}
</tbody></table></div>
}@else{<p>{{metric}}: unavailable. No substitute series.</p>}
}
}@else{<p>Unavailable: {{chart(view.key)?.reason || 'same-snapshot qualified complete calendar and required operands needed'}}. No sample trend is substituted.</p>}
</details>
}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}details{margin:14px 0}summary{cursor:pointer}.scroll{overflow:auto;max-width:100%}svg{display:block;width:100%;min-width:590px}text{font:12px sans-serif;fill:var(--text-primary,#475569)}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}`]})
export class FrozenActualTrendsComponent {
 readonly charts=input<any>(null);readonly snapshot=input('');readonly currency=input('');
 readonly views=[{key:'performance_trend',label:'Performance trend, separate metric axes'},{key:'spend_vs_conversions',label:'Spend and conversions over time, separate axes'},{key:'cpa_trend',label:'CPA trend'},{key:'roas_trend',label:'ROAS trend'}];
 chart(key:string):any{return this.charts()?.[key];}
 unit(metric:string):string{return metric==='conversion_value'?'reported value':metric==='cpa'?this.currency()+' / defined outcome':['spend','cpc','cpm'].includes(metric)?this.currency():metric==='roas'?'reported value / spend':metric==='ctr'||metric==='cvr'?'ratio':'reported count';}
 available(key:string):boolean{const c=this.chart(key);return c?.status==='available_data'&&c?.snapshot_id===this.snapshot()&&c?.type==='ACTUAL'&&Array.isArray(c.metrics)&&Array.isArray(c.points)&&c.points.length>0&&c.points.length<=3660&&c.points.every((p:any,i:number)=>p?.type==='ACTUAL'&&p.snapshot_id===this.snapshot()&&/^\d{4}-\d{2}-\d{2}$/.test(p.date)&&Number.isFinite(Date.parse(p.date+'T00:00:00Z'))&&new Date(p.date+'T00:00:00Z').toISOString().slice(0,10)===p.date&&(i===0||Date.parse(p.date+'T00:00:00Z')-Date.parse(c.points[i-1].date+'T00:00:00Z')===86400000));}
 exact(value:any):string{if(typeof value?.decimal==='string'&&/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value.decimal))return value.decimal;const f=value?.fraction;if(typeof f?.numerator==='string'&&typeof f?.denominator==='string'&&/^\d+$/.test(f.numerator)&&/^[1-9]\d*$/.test(f.denominator))return f.numerator+' / '+f.denominator;return 'Unavailable';}
 projection(value:any):number|null{if(this.exact(value)==='Unavailable')return null;let n:number;if(value.decimal!==null&&value.decimal!==undefined)n=Number(value.decimal);else n=Number(value.fraction.numerator)/Number(value.fraction.denominator);const zero=value.decimal!=null?/^0(?:\.0+)?$/.test(value.decimal):/^0+$/.test(value.fraction.numerator);return Number.isFinite(n)&&n>=0&&(n!==0||zero)?n:null;}
 plottable(key:string,metric:string):boolean{return this.available(key)&&this.chart(key).points.every((p:any)=>this.projection(p.ratios?.[metric])!==null);}
 maximum(key:string,metric:string):number{return Math.max(...this.chart(key).points.map((p:any)=>this.projection(p.ratios[metric])??0))||1;}
 axis(value:number):string{return value!==0&&(value<0.0001||value>=1e7)?value.toExponential(4):String(Number(value.toPrecision(6)));}
 x(i:number,count:number):number{return count===1?360:105+510*i/(count-1);}
 y(value:any,key:string,metric:string):number{return 200-170*((this.projection(value)??0)/this.maximum(key,metric));}
}
