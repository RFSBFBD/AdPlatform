<?php
namespace App\Services\OperationsResearch;
use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;
/** Minimize spend to reach reported conversion value under fixed monotone concave curves. */
final class TargetValueOptimizer
{
    public function optimize(array $channels,float $target,float $cap,float $minimumSpend=0): array
    {
        if (!$channels || !is_finite($target) || $target<=0 || !is_finite($cap) || $cap<=0 || !is_finite($minimumSpend) || $minimumSpend<0) { return ['status'=>'invalid_target','reason'=>'Positive finite target and budget cap, with valid curves, are required.']; }
        $entityMin=array_sum(array_column($channels,'min'));$lo=max($minimumSpend,$entityMin);$hi=min($cap,array_sum(array_column($channels,'max')));
        $atMin=0.0;$alloc=[];
        foreach ($channels as $c) {$atMin+=$c['curve']->revenueAt($c['min']);$alloc[$c['channel']]=$c['min'];}
        if ($hi<$lo) { return ['status'=>'infeasible','reason'=>'Minimum allocations exceed the cap.']; }
        if($lo>$entityMin){$floor=(new GenericCurveOptimizer)->optimize($lo,$channels)->toArray();if($floor['status']!=='ok')return ['status'=>'solver_not_converged','reason'=>'Minimum-total-spend feasibility could not be certified.'];$alloc=array_column($floor['channel_allocations'],'solver_spend','channel');$atMin=$this->value($channels,$floor);}
        if ($atMin>=$target) { return $this->output($channels,$alloc,$target,$lo,$atMin,0,[]); }
        $solver=new GenericCurveOptimizer();$top=$solver->optimize($hi,$channels)->toArray();
        $topValue=$this->value($channels,$top);
        if ($top['status']!=='ok') { return ['status'=>'solver_not_converged','reason'=>'Upper-cap feasibility could not be certified.']; }
        if ($topValue<$target) { return ['status'=>'infeasible','reason'=>'The target exceeds the modeled maximum within the stated cap and channel bounds.','modeled_upper_value'=>$topValue]; }
        $best=$top;$iterations=0;
        for ($i=0;$i<80 && $hi-$lo>max(1e-6,1e-8*$cap);$i++) {
            $mid=($hi+$lo)/2;$r=$solver->optimize($mid,$channels)->toArray();$iterations++;
            if ($r['status']!=='ok') { return ['status'=>'solver_not_converged','reason'=>'An inner solve failed its certificate. No target allocation reported.']; }
            if ($this->value($channels,$r)>=$target) { $hi=$mid;$best=$r; } else {$lo=$mid;}
        }
        $alloc=array_column($best['channel_allocations'],'solver_spend','channel');
        return $this->output($channels,$alloc,$target,$lo,$this->value($channels,$best),$iterations,$best['solver_certificate']);
    }
    private function value(array $channels,array $r): float
    {
        $allocation=array_column($r['channel_allocations']??[],'solver_spend','channel');$v=0.0;
        foreach ($channels as $ch) { if (!isset($allocation[$ch['channel']])) { return -INF; }$v+=$ch['curve']->revenueAt($allocation[$ch['channel']]); }
        return $v;
    }
    private function output(array $channels,array $alloc,float $target,float $lower,float $value,int $iterations,array $inner): array
    {
        $rows=[];foreach($channels as $c) {$x=$alloc[$c['channel']];$rows[]=['channel'=>$c['channel'],'spend'=>$x,'modeled_conversion_value'=>$c['curve']->revenueAt($x)];}
        return ['status'=>'ok','type'=>'SCENARIO','objective'=>'MINIMIZE_SPEND_FOR_TARGET_REPORTED_VALUE','target_reported_value'=>$target,'modeled_conversion_value'=>$value,'total_spend'=>array_sum($alloc),'budget_lower_bound'=>$lower,'budget_gap'=>array_sum($alloc)-$lower,'iterations'=>$iterations,'channels'=>$rows,'inner_certificate'=>$inner,'note'=>'Bisection on the maximum attainable value at each spend. Valid only for the fixed monotone concave curves and bounds; target value is not guaranteed revenue, profit or unique sales. Period is 30 days. No campaign changes.'];
    }
}
