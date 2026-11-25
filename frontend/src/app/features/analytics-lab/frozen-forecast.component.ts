import {Component,input} from '@angular/core';
import {CommonModule} from '@angular/common';
@Component({selector:'app-frozen-forecast',standalone:true,imports:[CommonModule],template:`<section><h4>Independent frozen forecast evaluation</h4>
@if(forecast()?.snapshot_id===snapshot() && forecast()?.status==='evaluated_uncalibrated_forecast'){
<p>EXPECTED approximate {{forecast().metric}}, not ACTUAL delivery or a budget-change scenario. Historical window {{forecast().source_window}} ({{forecast().timezone}}), {{forecast().history_days}} days. Selected {{forecast().selected}} under {{forecast().selection_policy}}. Calibration {{forecast().calibration}}.</p>
<p>Candidate set includes last value, clipped linear trend and weekly seasonal naive. The weekly candidate repeats the last seven training days recursively for longer horizons. Its selection is not proof that seasonality is stable.</p>
<div class="scroll"><table><thead><tr><th>Forecast date</th><th>Approximate expected {{unit()}}</th><th>Prediction interval</th></tr></thead><tbody>@for(point of forecast().points;track point.date){<tr><td>{{point.date}}</td><td>{{point.expected_approximate}}</td><td>{{point.interval_kind}}: {{point.interval_reason}}</td></tr>}</tbody></table></div>
@if(forecast().prequential_selection_evaluation;as evaluation){<details class="prequential"><summary>Later-origin evaluation of adaptive candidate selection: {{evaluation.status}}</summary>
<p>{{evaluation.limits}}</p>
<div class="scroll"><table><thead><tr><th>Lead days</th><th>Later evaluated origins</th><th>Approximate RMSE</th><th>Approximate MAE</th><th>Approximate bias</th></tr></thead><tbody>@for(lead of evaluation.evaluation_by_lead;track lead.lead_days){<tr><td>{{lead.lead_days}}</td><td>{{lead.evaluated_origins}}</td><td>{{lead.rmse_approximate}}</td><td>{{lead.mae_approximate}}</td><td>{{lead.bias_prediction_minus_actual_approximate}}</td></tr>}</tbody></table></div>
<div class="scroll"><table><thead><tr><th>Train through</th><th>Selection outcomes through</th><th>Completed prior origins</th><th>Selected then</th><th>Later test window</th></tr></thead><tbody>@for(origin of evaluation.origins;track origin.train_through){<tr><td>{{origin.train_through}}</td><td>{{origin.selection_outcomes_through}}</td><td>{{origin.completed_prior_origins}}</td><td>{{origin.selected}}</td><td>{{origin.test_from}} / {{origin.test_through}}</td></tr>}</tbody></table></div>
</details>}
<details><summary>Candidate errors by lead time and rolling origins</summary><p>RMSE/MAE/bias use {{unit()}}. Bias sign is prediction minus actual. Different lead times are not silently pooled in this table; candidate selection still uses the stated pooled requested-horizon policy. Errors are not confidence, prediction intervals or future success probabilities. Scroll horizontally for full operands.</p>
@for(candidate of forecast().evaluation_by_lead | keyvalue;track candidate.key){<h5>{{candidate.key}}</h5><div class="scroll"><table><thead><tr><th>Lead days</th><th>Evaluated origins</th><th>Approximate RMSE</th><th>Approximate MAE</th><th>Approximate bias</th></tr></thead><tbody>@for(lead of rows(candidate.value);track lead.lead_days){<tr><td>{{lead.lead_days}}</td><td>{{lead.evaluated_origins}}</td><td>{{lead.rmse_approximate}}</td><td>{{lead.mae_approximate}}</td><td>{{lead.bias_prediction_minus_actual_approximate}}</td></tr>}</tbody></table></div>}
@for(origin of forecast().origins;track origin.train_through){<details><summary>Train through {{origin.train_through}}, evaluate {{origin.test_from}} through {{origin.test_through}}</summary><p>Exact recorded outcomes, not predictions: {{origin.actual_recorded_exact | json}}.</p><p>Approximate train-only candidate predictions: {{origin.predictions | json}}.</p></details>}
</details>
@for(limit of forecast().limits;track limit){<p>{{limit}}</p>}
}@else{<p>Unavailable or unqualified for this frozen snapshot: {{forecast()?.reason || forecast()?.status || 'not requested'}}. No forecast, probability or interval substituted.</p>}
</section>`,styles:[`:host{display:block;min-width:0}section{margin:18px 0}p{font-size:13px;line-height:1.6;overflow-wrap:anywhere}.scroll{overflow:auto}table{border-collapse:collapse;font-size:12px;width:100%}td,th{padding:9px;border-bottom:1px solid #dce4ef;white-space:nowrap;text-align:left}details{margin:12px 0}summary{cursor:pointer}`]})
export class FrozenForecastComponent{
 readonly forecast=input<any>(null);readonly snapshot=input('');readonly unit=input('declared metric units');
 rows(value:any):any[]{return Array.isArray(value)?value:[];}
}
