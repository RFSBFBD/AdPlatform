<?php
namespace App\Services\Analytics;
use InvalidArgumentException;

/** Deterministic path attribution on explicitly supplied first-party journeys.
 * Does not merge provider-reported totals or establish customer identity.
 */
final class AttributionEngine
{
    public function attribute(array $journeys,string $model,array $options=[]): array
    {
        if (!in_array($model,['first_click','last_click','linear','time_decay','position_based','data_driven'],true)) { throw new InvalidArgumentException('Unsupported attribution model.'); }
        if ($model==='data_driven') { return ['status'=>'not_computable','reason'=>'No trained and validated causal or probabilistic attribution model. Rule-based weights are not data-driven attribution.','allocations'=>[]]; }
        $halfLife=(float)($options['half_life_days']??7);
        if (!is_finite($halfLife) || $halfLife<=0) { throw new InvalidArgumentException('Half-life must be positive and finite.'); }
        $conversionIds=[];$alloc=[];$currency=null;$details=[];$total=0.0;
        foreach ($journeys as $j) {
            $id=$j['conversion_id']??'';
            if (!is_string($id) || $id==='' || isset($conversionIds[$id])) { throw new InvalidArgumentException('Each supplied conversion must have a unique nonempty id. Deduplicate from the first-party source before submitting.'); }
            $conversionIds[$id]=true;$cur=$j['currency']??'';$value=$j['value']??null;
            if (!preg_match('/^[A-Z]{3}$/',$cur) || !is_numeric($value) || !is_finite((float)$value) || $value<0) { throw new InvalidArgumentException('Conversion value/currency invalid.'); }
            if ($currency!==null && $currency!==$cur) { throw new InvalidArgumentException('Mixed currencies cannot be allocated as one total.'); }$currency=$cur;
            $conversion=$this->timestamp($j['converted_at']??'');$touches=$j['touches']??[];
            if (!is_array($touches) || $touches===[] || count($touches)>1000) { throw new InvalidArgumentException('A journey requires 1-1000 timestamped clicks.'); }
            $events=[];$last=null;$times=[];
            foreach ($touches as $i=>$t) {
                $event=$t['event_id']??'';$channel=$t['channel']??'';$time=$this->timestamp($t['at']??'');
                if (!is_string($event) || $event==='' || isset($events[$event]) || !is_string($channel) || !preg_match('/^[a-z0-9_-]{1,64}$/',$channel)) { throw new InvalidArgumentException('Click ids must be unique within journey and channels explicit.'); }
                if ($time>$conversion || ($last!==null && $time<$last)) { throw new InvalidArgumentException('Clicks must be ordered and at or before conversion.'); }
                // Tied times at different positions make first/last ambiguous, reject instead of arbitrary credit.
                if ($last!==null && $time===$last && in_array($model,['first_click','last_click','position_based'],true)) { throw new InvalidArgumentException('Equal click timestamps make position attribution ambiguous.'); }
                $events[$event]=true;$last=$time;$times[]=$time;
            }
            $n=count($touches);$weights=array_fill(0,$n,0.0);
            switch ($model) {
                case 'first_click':$weights[0]=1.0;break;
                case 'last_click':$weights[$n-1]=1.0;break;
                case 'linear':$weights=array_fill(0,$n,1/$n);break;
                case 'position_based':
                    if ($n===1) { $weights=[1.0]; } elseif ($n===2) { $weights=[.5,.5]; }
                    else { $weights=array_fill(0,$n,.2/($n-2));$weights[0]=.4;$weights[$n-1]=.4; }break;
                case 'time_decay':
                    // Subtract most recent age to prevent underflow on old journeys.
                    $latest=max($times);$weights=array_map(fn($t)=>exp(-log(2)*(($latest-$t)/86400)/$halfLife),$times);$sum=array_sum($weights);$weights=array_map(fn($w)=>$w/$sum,$weights);break;
            }
            $credits=[];
            foreach ($touches as $i=>$t) {
                $w=$weights[$i];$c=$t['channel'];$alloc[$c]??=['conversion_credit'=>0.0,'value_credit'=>0.0];
                $alloc[$c]['conversion_credit']+=$w;$alloc[$c]['value_credit']+=$w*(float)$value;
                $credits[]=['event_id'=>$t['event_id'],'channel'=>$c,'weight'=>$w,'value_credit'=>$w*(float)$value];
            }
            $total+=(float)$value;$details[]=['conversion_id'=>$id,'credits'=>$credits];
        }
        ksort($alloc);
        return ['status'=>$journeys===[]?'insufficient_data':'ok','model'=>$model,'type'=>'ATTRIBUTED_RULE_BASED','currency'=>$currency,'conversion_count'=>count($conversionIds),'input_value'=>$total,'allocations'=>$alloc,'journeys'=>$details,'parameters'=>$model==='time_decay'?['half_life_days'=>$halfLife]:($model==='position_based'?['first'=>.4,'last'=>.4,'middle_total'=>.2]:[]),'limits'=>['Only submitted, already-deduplicated first-party conversion journeys are allocated. No identity resolution or cross-platform deduplication is performed.','Rule-based credit is not causal incrementality, not platform reporting truth and not a forecast.','Touch input means clicks only. Views and organic/direct touches require separately verified semantics.','No external accounts or campaigns are changed.']];
    }
    /** Same submitted journeys for every rule, never a cross-provider uniqueness estimate. */
    public function compare(array $journeys,array $options=[]):array
    {
        $models=[];$channels=[];
        foreach(['first_click','last_click','linear','time_decay','position_based']as$model){
            $r=$this->attribute($journeys,$model,$options);
            if($r['status']!=='ok')return $r;
            $models[$model]=['allocations'=>$r['allocations'],'parameters'=>$r['parameters']];
            foreach(array_keys($r['allocations'])as$c)$channels[$c]=true;
        }
        ksort($channels);$rows=[];
        foreach(array_keys($channels)as$c){$credits=[];foreach($models as$m=>$v)$credits[$m]=$v['allocations'][$c]??['conversion_credit'=>0.,'value_credit'=>0.];$values=array_column($credits,'value_credit');$rows[]=['channel'=>$c,'models'=>$credits,'value_credit_range'=>max($values)-min($values)];}
        return ['status'=>'ok','type'=>'ATTRIBUTION_MODEL_COMPARISON','currency'=>$r['currency'],'conversion_count'=>$r['conversion_count'],'input_value'=>$r['input_value'],'models'=>$models,'rows'=>$rows,'limits'=>array_merge($r['limits'],['All five rules use exactly the same submitted journeys, currency and time-decay half-life. No lookback filter or window is silently imposed. Credit range is disagreement between rules, not uncertainty, confidence or evidence of which model is correct.','Data-driven attribution is excluded because no trained validated model exists. Equal timestamps that make positional models ambiguous reject the entire comparison.'])];
    }
    private function timestamp(string $s): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',$s)) { throw new InvalidArgumentException('Use explicit ISO8601 timestamps with timezone.'); }
        try { $d=new \DateTimeImmutable($s); } catch (\Throwable) { throw new InvalidArgumentException('Invalid timestamp.'); }
        $errors=\DateTimeImmutable::getLastErrors();if ($errors!==false && ($errors['warning_count'] || $errors['error_count'])) { throw new InvalidArgumentException('Invalid timestamp.'); }return $d->getTimestamp();
    }
}
