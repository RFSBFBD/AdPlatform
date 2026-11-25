<?php
namespace App\Services\OperationsResearch;
use App\Services\Analytics\Curves\ScaledResponseCurve;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
/** Exact counterpart for separable nonnegative curves and independent scale boxes. */
final class BoxRobustOptimizer
{
    public function optimize(array $channels,float $budget,array $bounds,array $constraints=[]): array
    {
        $known=array_column($channels,'channel');
        if (!$channels || array_diff($known,array_keys($bounds)) || array_diff(array_keys($bounds),$known)) { return ['status'=>'not_computable','reason'=>'Explicit scale uncertainty for every included channel is required, no extra channels.']; }
        $worst=$channels;
        foreach ($channels as $i=>$ch) {
            $u=$bounds[$ch['channel']];
            if (!is_numeric($u) || !is_finite((float)$u) || $u<0 || $u>=1) { return ['status'=>'invalid_uncertainty','reason'=>'Each bound must be a fractional relative scale error in [0,1).']; }
            if ($ch['curve']->revenueAt($ch['min'])<0) { return ['status'=>'not_computable','reason'=>'Box counterpart requires nonnegative response curves.']; }
            $worst[$i]['curve']=new ScaledResponseCurve($ch['curve'],1-(float)$u);
        }
        $result=(new GenericCurveOptimizer())->optimize($budget,$worst,$constraints)->toArray();
        if ($result['status']!=='ok') { return ['status'=>$result['status'],'reason'=>$result['status_message']??'No feasible robust scenario.']; }
        $allocation=array_column($result['channel_allocations'],'solver_spend','channel');$nominal=$lower=$upper=0.0;$rows=[];
        foreach ($channels as $ch) {
            $x=$allocation[$ch['channel']];$v=$ch['curve']->revenueAt($x);$u=(float)$bounds[$ch['channel']];
            $nominal+=$v;$lower+=$v*(1-$u);$upper+=$v*(1+$u);
            $rows[]=['channel'=>$ch['channel'],'spend'=>$x,'nominal_conversion_value'=>$v,'worst_case_conversion_value'=>$v*(1-$u),'best_case_conversion_value'=>$v*(1+$u),'scale_error_bound'=>$u];
        }
        return ['status'=>'ok','type'=>'SCENARIO','objective'=>'MAXIMIZE_WORST_CASE_REPORTED_VALUE','uncertainty_set'=>'independent_channel_output_scale_box','bounds'=>$bounds,'channels'=>$rows,'nominal_conversion_value'=>$nominal,'worst_case_conversion_value'=>$lower,'best_case_conversion_value'=>$upper,'certificate'=>$result['solver_certificate'],'allocation_total'=>array_sum($allocation),'note'=>'Minimax counterpart: nonnegative channel outputs attain their joint minimum at every lower scale bound. These are explicit user assumptions, not confidence intervals or probabilities. Does not protect against timing, attribution, curve-shape error or account changes. No campaign changes.'];
    }
}
