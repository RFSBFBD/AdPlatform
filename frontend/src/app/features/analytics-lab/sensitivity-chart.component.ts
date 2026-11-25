import {Component,input} from '@angular/core';
import {DecimalPipe} from '@angular/common';
@Component({selector:'app-sensitivity-chart',standalone:true,imports:[DecimalPipe],template:`
@if(sensitivity();as s){@if(s.runs?.length){<h4>Allocation sensitivity by perturbation</h4><p>Largest absolute allocation-share shift in each re-solve, measured in percentage points. Zero means unchanged allocation, not unchanged response. Failed runs have no bar.</p>
<div class="chart-wrap"><svg [attr.viewBox]="'0 0 700 '+height()" role="img" aria-label="Allocation-share sensitivity per curve-scale perturbation">
<line x1="285" y1="24" x2="285" [attr.y2]="height()-32" stroke="#94a3b8"/>
@for(r of s.runs;track $index){<text x="275" [attr.y]="50+$index*32" text-anchor="end">{{r.channel}} · ×{{r.scale | number:'1.2-2'}}</text>
@if(valid(r)){<rect x="285" [attr.y]="39+$index*32" [attr.width]="350*r.max_share_shift_pp/axisMax()" height="14" fill="#4f46e5"/><circle cx="285" [attr.cy]="46+$index*32" r="2" fill="#4f46e5"/><text [attr.x]="295+350*r.max_share_shift_pp/axisMax()" [attr.y]="50+$index*32">{{r.max_share_shift_pp | number:'1.0-2'}}</text>}@else{<text x="295" [attr.y]="50+$index*32">Not computed</text>}}
<text x="285" [attr.y]="height()-8">0</text><text x="635" [attr.y]="height()-8" text-anchor="end">{{axisMax() | number:'1.0-2'}} pp</text></svg></div><p class="scroll-hint">On narrow screens, scroll the chart and table horizontally to inspect all values.</p>
<div class="table-wrap"><table><thead><tr><th>Entity / channel</th><th>Output scale</th><th>Status</th><th>Largest share shift (pp)</th></tr></thead><tbody>@for(r of s.runs;track $index){<tr><td>{{r.channel}}</td><td>{{r.scale | number:'1.2-2'}}</td><td>{{valid(r)?'Completed':(r.status || 'Unavailable')}}</td><td>{{valid(r)?(r.max_share_shift_pp | number:'1.0-2'):'Undefined'}}</td></tr>}</tbody></table></div><p>Explicit one-at-a-time output-scale perturbations. Not a probability, confidence interval, sign of causal lift, or direction of a specific entity's move. Chart axis adapts to the observed shifts, inspect its pp labels.</p>}}
`,styles:[`:host{display:block;min-width:0}svg{display:block;width:100%;max-height:800px;margin:12px 0}text{font:12px sans-serif;fill:var(--text-primary,#334155)}h4{margin:20px 0 8px}p{font-size:12px;line-height:1.6;color:var(--text-muted,#64748b)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:10px;text-align:left;border-bottom:1px solid #e2e8f0;white-space:nowrap}th{background:var(--bg-secondary,#f1f5f9)}.chart-wrap{overflow:auto}.scroll-hint{display:none}@media(max-width:600px){svg{min-width:650px}.scroll-hint{display:block}}`]})
export class SensitivityChartComponent{
 readonly sensitivity=input<any>(null);
 valid(r:any):boolean{return typeof r.max_share_shift_pp==='number'&&Number.isFinite(r.max_share_shift_pp)&&r.max_share_shift_pp>=0&&r.status!=='infeasible';}
 axisMax():number{return Math.max(1,...(this.sensitivity()?.runs||[]).filter((r:any)=>this.valid(r)).map((r:any)=>r.max_share_shift_pp));}
 height():number{return 80+32*(this.sensitivity()?.runs?.length||0);}
}
