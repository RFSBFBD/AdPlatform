import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
/** Numeric display projection only. Original allocation strings remain in adjacent existing tables. */
@Component({selector:'app-frozen-comparison-plots',standalone:true,imports:[CommonModule],template:`
<section><h4>Frozen allocation, scenario and sensitivity plots</h4>
<p>SCENARIO display projections, not ACTUAL delivery, causal lift, confidence or permission to spend. Each panel uses its own zero-based axis and units. Scroll horizontally for full labels on narrow screens. Exact proposed allocation operands remain in the scenario/sensitivity tables.</p>
@if(chart()?.snapshot_id===snapshot()){
@for(scenario of chart()?.modeled_scenarios;track scenario.name){
@if(scenario.check_status==='checked'){
<details><summary>{{scenario.name}} budget allocation</summary>
@if(allocationBars(scenario.allocation);as bars){
<p>Approximate axis in {{currency()}}. All atomic variables are displayed, including zero budgets. No overlapping group totals are added.</p>
<ng-container *ngTemplateOutlet="plot;context:{$implicit:bars,title:scenario.name+' allocation',unit:currency()}" />
}@else{<p>Plot unavailable: finite nonnegative budget projection required. Exact operands remain in the allocation table.</p>}
</details>}}
@for(metric of metrics;track metric.key){<details><summary>{{metric.label}} across checked scenarios</summary>
@if(scenarioBars(metric.key);as bars){<ng-container *ngTemplateOutlet="plot;context:{$implicit:bars,title:metric.label+' comparison',unit:metric.key==='spend_exact'?currency():responseUnit()}" />}
@else{<p>Unavailable: checked finite nonnegative same-snapshot operands required. Rejected scenarios are not plotted.</p>}
</details>}
}@else{<p>Allocation/scenario plots unavailable: matching frozen scenario evidence required.</p>}
@if(sensitivity()?.snapshot_id===snapshot()){
@for(metric of metrics;track metric.key){<details><summary>Reoptimized {{metric.label}} by declared budget-cap shock</summary>
@if(sensitivityBars(metric.key);as bars){<p>Only independently checked reoptimizations shown. Labels are cap multipliers, not probabilities. Fixed-allocation stress is separate.</p><ng-container *ngTemplateOutlet="plot;context:{$implicit:bars,title:'Budget cap sensitivity '+metric.label,unit:metric.key==='spend_exact'?currency():responseUnit()}" />}
@else{<p>Unavailable: no checked finite reoptimization to plot.</p>}
</details>}
}@else{<p>Sensitivity plots unavailable or not requested for this snapshot.</p>}
<ng-template #plot let-bars let-title="title" let-unit="unit"><div class="scroll"><svg [attr.viewBox]="'0 0 760 '+height(bars)" role="img" [attr.aria-label]="title+'; approximate '+unit+' axis'">
<text x="230" y="20">0</text><text x="675" y="20" text-anchor="end">{{axis(maximum(bars))}} {{unit}}</text>
<line x1="230" y1="27" x2="230" [attr.y2]="height(bars)-15" stroke="#94a3b8"/>
@for(bar of bars;track $index){<text x="217" [attr.y]="49+36*$index" text-anchor="end">{{short(bar.label)}}<title>{{bar.label}}</title></text><rect x="230" [attr.y]="33+36*$index" [attr.width]="440*bar.value/maximum(bars)" height="23" fill="#4f46e5"><title>{{bar.label}}: {{bar.original}} {{unit}}</title></rect><text [attr.x]="238+440*bar.value/maximum(bars)" [attr.y]="49+36*$index">{{axis(bar.value)}}</text>}
</svg></div></ng-template>
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}details{margin:12px 0}summary{cursor:pointer}.scroll{overflow:auto;max-width:100%}svg{display:block;min-width:740px;width:100%}text{font:11px sans-serif;fill:var(--text-primary,#475569)}`]})
export class FrozenComparisonPlotsComponent{
 readonly chart=input<any>(null);readonly sensitivity=input<any>(null);readonly snapshot=input('');readonly currency=input('');readonly responseUnit=input('');readonly metrics=[{key:'spend_exact',label:'Modeled spend'},{key:'response_approximate',label:'Modeled response'}];
 project(value:any):number|null{if(typeof value!=='string'&&typeof value!=='number')return null;if(typeof value==='string'&&!/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value))return null;const n=Number(value);return Number.isFinite(n)&&n>=0&&(n!==0||typeof value==='number'||/^0(?:\.0+)?$/.test(value))?n:null;}
 bars(values:{label:string,value:any}[]):any[]|null{if(!values.length||values.length>100)return null;const bars=values.map(v=>({label:v.label,original:v.value,value:this.project(v.value)}));return bars.some(b=>b.value===null)?null:bars;}
 allocationBars(allocation:any):any[]|null{return allocation&&typeof allocation==='object'?this.bars(Object.entries(allocation).map(([label,value])=>({label,value}))):null;}
 scenarioBars(metric:string):any[]|null{if(this.chart()?.snapshot_id!==this.snapshot())return null;return this.bars((this.chart()?.modeled_scenarios??[]).filter((s:any)=>s.check_status==='checked').map((s:any)=>({label:s.name,value:s[metric]})));}
 sensitivityBars(metric:string):any[]|null{if(this.sensitivity()?.snapshot_id!==this.snapshot())return null;return this.bars((this.sensitivity()?.scenarios??[]).filter((s:any)=>s.reoptimized?.check?.status==='checked').map((s:any)=>({label:'Cap × '+s.scale,value:s.reoptimized.check[metric]})));}
 maximum(bars:any[]):number{return Math.max(...bars.map(b=>b.value))||1;}height(bars:any[]):number{return 50+36*bars.length;}
 axis(n:number):string{return n!==0&&(n<0.0001||n>=1e6)?n.toExponential(3):String(Number(n.toPrecision(5)));}short(label:string):string{return label.length>30?label.slice(0,27)+'...':label;}
}
