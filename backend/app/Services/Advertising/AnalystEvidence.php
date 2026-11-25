<?php
namespace App\Services\Advertising;

/** Six-block, allowlisted mathematical evidence. No account identifiers, names, tokens or raw rows. */
final class AnalystEvidence
{
    public function build(array $or, string $snapshotId): array
    {
        $ok=($or['status']??null)==='ok';
        $actual=[];
        if($ok)foreach($or['channel_allocations']??[] as $a){
            $actual[]=['platform'=>$a['channel'],'spend'=>$a['current_spend']??null,'platform_reported_value'=>$a['current_revenue']??null,'roas'=>$a['current_roas']??null];
        }
        $alloc=[];
        if($ok)foreach($or['channel_allocations']??[] as $a){
            $alloc[]=['platform'=>$a['channel'],'spend'=>$a['optimal_spend']??null,'modeled_value'=>$a['optimal_revenue']??null,'same_budget_baseline_spend'=>$a['baseline_spend_same_budget']??null];
        }
        return [
            'actual_metrics'=>['type'=>'ACTUAL','snapshot_id'=>$snapshotId,'scope'=>'Only quality-gated included optimization channels, not all connected accounts. Platform-reported value, not deduplicated sales or profit.','status'=>$ok?'available':'not_available_in_this_snapshot','channels'=>$actual,'window'=>$or['gates']['window']??$or['data_quality']['window']??null],
            'forecast'=>['type'=>'EXPECTED','status'=>'not_computed_for_this_snapshot','points'=>[],'reason'=>'Independent forecast results were not computed for this optimization snapshot. Scenario values are not forecasts.'],
            'optimization'=>['type'=>'SCENARIO','status'=>$or['status']??'unknown','objective'=>$or['objective']??null,'budget'=>$ok?($or['total_budget']??null):null,'modeled_value'=>$ok?($or['optimal_total_revenue']??null):null,'allocations'=>$alloc,'certificate'=>$or['solver_certificate']??[],'action'=>'SIMULATION_ONLY'],
            'diagnostics'=>['status'=>'not_computed_for_this_snapshot','reason'=>'Campaign diagnostics use their own entity windows; they are not inferred from budget allocations.'],
            'confidence'=>['allocation_confidence'=>null,'reason'=>'No calibrated confidence probability for allocation shifts. Stability and stress ranges are not confidence intervals.','stability'=>$or['sensitivity']['stability']??null,'stress_status'=>$or['stress']['status']??'not_computable'],
            'data_quality'=>['portfolio'=>$or['data_quality']['portfolio']??[],'window'=>$or['data_quality']['window']??null,'note'=>'Engineering data-quality rubric, not a calibrated statistical probability.'],
        ];
    }
}
