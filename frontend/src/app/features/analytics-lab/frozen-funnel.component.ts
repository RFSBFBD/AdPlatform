import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-funnel',standalone:true,imports:[CommonModule],template:`<section><h4>Explicit linked event funnel</h4>
@if(funnel()?.snapshot_id===snapshot() && funnel()?.stages && funnel()?.status!=='unavailable'){
<p>{{funnel().status}}. Source events, not unique people. Supplied population {{funnel().scope.population}}, window {{funnel().scope.reporting_window}}. This is not a provider inventory or a causal bottleneck diagnosis.</p>
<ol>@for(stage of stages;track stage){<li><strong>{{stage}}</strong><span>{{funnel().stages[stage]?.count_exact ?? 'Unavailable'}} source events</span>@if(funnel().stages[stage]?.transition;as t){<p>{{t.parents_with_child_exact}} / {{t.previous_event_count_exact}} previous-stage events have a child. {{t.parents_without_child_exact}} have no supplied child. Parent coverage {{fraction(t.parent_coverage_fraction_exact)}}. Multiple children per parent allowed.</p>}@if(funnel().stages[stage]?.reason){<p>{{funnel().stages[stage].reason}}</p>}</li>}</ol>
<p>Declared purchase value {{funnel().revenue.value_exact ?? 'Unavailable'}} {{funnel().revenue.currency}}. Not verified sales, profit or refund-adjusted revenue.</p>
@for(limit of funnel().limits;track limit){<p>{{limit}}</p>}
@if(attribution()?.snapshot_id===snapshot() && attribution()?.status==='computed_declared_single_click_credit'){
<details><summary>Declared single-click purchase credit, exact conserved accounting</summary><p>This parent chain contains one selected click per purchase, not a complete multi-touch journey. It cannot identify first/last touch or train data-driven attribution. Credits are not added to advertising totals.</p>
@for(entry of attribution().credits | keyvalue;track entry.key){<p>Click event {{entry.key}}. Purchase-event credit {{fraction(row(entry.value).purchase_credit_exact)}}. Declared value credit {{fraction(row(entry.value).declared_value_credit_exact)}} {{attribution().currency}}.</p>}
<p>Purchase credit conservation {{attribution().credit_conservation}}. Value conservation {{attribution().value_conservation ?? 'Unavailable'}}.</p>
@for(limit of attribution().limits;track limit){<p>{{limit}}</p>}</details>}

}@else{<p>Unavailable: {{funnel()?.reason || 'matching frozen linked event evidence required'}}. Recorded clicks and a conversion total do not establish linked landing, lead, qualified-lead and purchase stages.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}ol{list-style:none;padding:0;display:grid;gap:10px}li{border:1px solid #dce4ef;border-left:4px solid #7767d5;border-radius:8px;padding:12px}strong{display:inline-block;min-width:150px}span{font-size:14px}`]})
export class FrozenFunnelComponent{readonly attribution=input<any>(null);row(v:any):any{return v;}readonly funnel=input<any>(null);readonly snapshot=input('');readonly stages=['impression','click','landing','lead','qualified_lead','purchase'];fraction(v:any):string{return v?.denominator?`${v.numerator}/${v.denominator}`:'Unavailable (zero previous-stage events)';}}
