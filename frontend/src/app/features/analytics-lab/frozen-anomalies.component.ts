import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-anomalies',standalone:true,imports:[CommonModule],template:`
<section><h4>Frozen retrospective anomaly screens</h4>
@if(evidence()?.snapshot_id===snapshot() && evidence()?.status==='computed_retrospective_screens'){
<p>DESCRIPTIVE, not a live alarm, calibrated significance test, health verdict or causal diagnosis. Full-window screening can use later days to describe earlier days. No absence-of-problem claim follows from no flags. False-alarm probability is unavailable.</p>
<p>Policy {{evidence().policy.version}}. Eight-day comparison minimum for IQR/MAD/EWMA; twelve for one-split scan. These engineering minimums do not establish statistical sufficiency.</p>
@for(limit of evidence().limits;track limit){<p>{{limit}}</p>}
@for(entity of evidence().entities | keyvalue;track entity.key){<details><summary>{{entity.key}}: frozen reported metrics</summary>
@for(metric of row(entity.value) | keyvalue;track metric.key){<details><summary>{{metric.key}}: {{row(metric.value).status}}</summary>
@if(row(metric.value).status==='screened_retrospectively'){
<p>{{row(metric.value).dates.length}} consecutive supplied days. Threshold settings: IQR multiplier {{evidence().policy.iqr_k}}, robust-z threshold {{evidence().policy.robust_z_threshold}}, EWMA weight {{evidence().policy.ewma_lambda}} and limit {{evidence().policy.ewma_limit}}, split score threshold {{evidence().policy.change_point_min_z}}. These are not probabilities.</p>
<div class="scroll"><table><thead><tr><th>Method</th><th>State</th><th>Flags or detected split</th></tr></thead><tbody>
@for(method of methods;track method){<tr><th>{{method}}</th><td>{{row(metric.value).analysis[method].status}}</td><td>@if(method==='change_point'){Detected: {{row(metric.value).analysis[method].detected}}. Date {{row(metric.value).analysis[method].date || 'Unavailable'}}}@else{ {{row(metric.value).analysis[method].flags?.length || 0}} flagged dates }</td></tr>}
</tbody></table></div>
<div class="scroll"><table><thead><tr><th>Flagged date</th><th>Exact recorded {{metric.key}}</th><th>Methods</th></tr></thead><tbody>
@for(flag of row(metric.value).analysis.flagged_points;track flag.index){<tr><td>{{flag.date}}</td><td>{{flag.operand_recorded_exact}}</td><td>{{flag.methods.join(', ')}}</td></tr>}
</tbody></table></div>
@if(!row(metric.value).analysis.flagged_points.length){<p>No point flags from applicable methods. Degenerate and insufficient-history methods did not clear the series.</p>}
}@else{<p>{{row(metric.value).reason}}. No missing date or operand replaced with zero.</p>}
</details>}
</details>}
}@else{<p>Unavailable: {{evidence()?.reason || 'matching-snapshot qualified complete daily series required'}}. No fabricated anomaly or healthy verdict.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}details{margin:12px 0}summary{cursor:pointer}.scroll{overflow:auto;max-width:100%}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}`]})
export class FrozenAnomaliesComponent{readonly evidence=input<any>(null);readonly snapshot=input('');readonly methods=['iqr','robust_z','ewma','change_point'];row(value:any):any{return value;}}
