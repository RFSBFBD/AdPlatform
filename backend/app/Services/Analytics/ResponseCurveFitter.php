<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Curves\ResponseCurve;

/**
 * Adaptive Response Curve Fitting (Master Spec sections 17-19, 47-48).
 *
 * Tries the candidate models (linear / log / power / saturation) on the
 * channel's daily (spend, response) history and selects by:
 *   Score = ValidationError + ComplexityPenalty + InstabilityPenalty
 * (spec section 19). No model is used if it fails validation.
 *
 * Every candidate is linear in its scale parameter `a` given shape `b`, so `a`
 * is solved in closed form (least squares) and `b` is scanned on a FIXED grid:
 * fully deterministic, no random restarts.
 *
 * What this is NOT (returned in `limits`): an observational fit of spend vs
 * reported response does not prove causal budget lift, does not establish the
 * response outside the observed spend support, and reflects the platform's own
 * attribution. The fitter abstains when history is too short or too flat.
 */
final class ResponseCurveFitter
{
    /** Fixed shape grids (deterministic). */
    private const LOG_B = [1e-5, 3e-5, 1e-4, 3e-4, 1e-3, 3e-3, 1e-2, 3e-2, 1e-1, 3e-1, 1.0];
    private const POWER_B = [0.05, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 0.95, 1.0, 1.2, 1.5, 2.0];
    private const SAT_B = [1e-6, 3e-6, 1e-5, 3e-5, 1e-4, 3e-4, 1e-3, 3e-3, 1e-2, 3e-2, 1e-1];

    /**
     * @param array $points chronological [ ['spend'=>float,'response'=>float], ... ] complete days
     * @return array selection result; status ok|insufficient_data|no_valid_model
     */
    public function fit(array $points,bool $includePiecewise=false): array
    {
        $cfg = config('analytics.curve_fit');
        $minDays = (int) $cfg['min_days'];

        foreach ($points as $p) {
            if (!isset($p['spend'],$p['response']) || !is_numeric($p['spend']) || !is_numeric($p['response']) || !is_finite((float)$p['spend']) || !is_finite((float)$p['response']) || $p['spend']<0 || $p['response']<0) { return $this->abstain('invalid_data','Missing, nonfinite or negative input cannot be silently removed from a chronological fit.',count($points)); }
        }

        // Only days with spend > 0 inform a spend-response curve.
        $pts = array_values(array_filter($points, fn($p) => ($p['spend'] ?? 0) > 0 && is_finite((float) $p['spend']) && is_finite((float) ($p['response'] ?? NAN))));
        $n = count($pts);
        if ($n < $minDays) {
            return $this->abstain('insufficient_data', "Curve fitting needs at least {$minDays} days with spend > 0; got {$n}.", $n);
        }
        $levels = count(array_unique(array_map(fn($p) => round((float) $p['spend'], 2), $pts)));
        if ($levels < (int) $cfg['min_spend_levels']) {
            return $this->abstain('insufficient_data', "Spend barely varies ({$levels} distinct levels); a response curve cannot be identified from it.", $n);
        }

        $valShare = (float) $cfg['validation_share'];
        $valN = max((int) $cfg['min_validation_points'], (int) floor($n * $valShare));
        $valN = min($valN, $n - 5); // keep at least 5 training points
        if ($valN < 2 || $n - $valN < 5) {
            return $this->abstain('insufficient_data', 'Not enough points to hold out a validation segment.', $n);
        }
        $train = array_slice($pts, 0, $n - $valN);
        $valid = array_slice($pts, $n - $valN);

        $candidates = [];
        foreach ($this->candidateFits($train,$includePiecewise) as $cand) {
            $candidates[] = $cand;
        }

        $scored = [];
        foreach ($candidates as $cand) {
            $valRmse = $this->relRmse($cand['curve'], $valid);
            if ($valRmse === null) {
                $scored[] = $cand + ['status' => 'rejected', 'reject_reason' => 'validation_undefined'];
                continue;
            }
            $complexity = ((float) $cand['n_params'] - 1.0) * (float) $cfg['complexity_penalty'];
            $instability = $this->instability($cand['family'], $train) * (float) $cfg['instability_weight'];
            $score = $valRmse + $complexity + $instability;
            $scored[] = $cand + [
                'status' => $valRmse <= (float) $cfg['max_validation_rmse_rel'] ? 'valid' : 'rejected',
                'reject_reason' => $valRmse <= (float) $cfg['max_validation_rmse_rel'] ? null : 'validation_rmse_above_threshold',
                'validation_rmse_rel' => round($valRmse, 6),
                'complexity_penalty' => round($complexity, 6),
                'instability_penalty' => round($instability, 6),
                'score' => round($score, 6),
            ];
        }

        $validModels = array_values(array_filter($scored, fn($c) => $c['status'] === 'valid' && ($c['family'] !== 'power' || $c['params']['b'] < 1.0)));
        // power with b >= 1 is non-concave: excluded from optimization candidates (documented reason).
        foreach ($scored as $i => $c) {
            if ($c['family'] === 'power' && ($c['params']['b'] ?? 0) >= 1.0 && $c['status'] === 'valid') {
                $scored[$i]['status'] = 'rejected';
                $scored[$i]['reject_reason'] = 'non_concave_shape_not_optimizable';
            }
        }

        if ($validModels === []) {
            return $this->abstain('no_valid_model', 'Every candidate failed validation (holdout error above threshold or non-concave shape). No curve is used; fall back to an explicit assumed curve or collect more varied data.', $n, $scored);
        }

        usort($validModels, fn($a, $b) => $a['score'] <=> $b['score']);
        $best = $validModels[0];
        // Refit the winner on ALL points for the production curve.
        $full = $this->fitFamily($best['family'], $pts);

        if ($full===null || $best['family']==='power' && $full['params']['b']>=1) { return $this->abstain('no_valid_model','Refitted full-data model failed concavity or numerical checks.',$n,$scored); }

        return [
            'status' => 'ok',
            'model' => $best['family'],
            'curve' => $full['curve'],
            'params' => $full['params'],
            'validation' => [
                'method' => 'chronological_holdout',
                'train_days' => count($train),
                'validation_days' => count($valid),
                'validation_rmse_rel' => $best['validation_rmse_rel'],
                'score' => $best['score'],'complexity_penalty'=>$best['complexity_penalty'],'instability_penalty'=>$best['instability_penalty'],
                'threshold' => (float) $cfg['max_validation_rmse_rel'],
                'holdout_predictions'=>array_map(fn($point,$i)=>['positive_spend_observation_index'=>count($train)+$i+1,'daily_spend'=>(float)$point['spend'],'observed_response'=>(float)$point['response'],'predicted_response'=>$best['curve']->revenueAt((float)$point['spend']),'residual'=>(float)$point['response']-$best['curve']->revenueAt((float)$point['spend'])],$valid,array_keys($valid)),
                'prediction_note'=>'Held-out observations evaluated using the TRAIN-ONLY winner fit, before full-data refit. Observation index counts positive-spend days in supplied chronological history, not a calendar date. Residual is observed minus fitted prediction. These are validation predictions, not forecasts, causal effects or prediction intervals.',
            ],
            'candidates' => array_map(fn($c) => [
                'family' => $c['family'],
                'status' => $c['status'],
                'reject_reason' => $c['reject_reason'] ?? null,
                'validation_rmse_rel' => $c['validation_rmse_rel'] ?? null,
                'score' => $c['score'] ?? null,'complexity_penalty'=>$c['complexity_penalty']??null,'instability_penalty'=>$c['instability_penalty']??null,
            ], $scored),
            'support_min_spend' => min(array_map(fn($p) => (float) $p['spend'], $pts)),
            'support_max_spend' => max(array_map(fn($p) => (float) $p['spend'], $pts)),
            'days_used' => $n,
            'limits' => 'Observational fit on platform-attributed data: not causal proof, not valid far beyond the observed spend support, and not unique-sales measurement.',
        ];
    }

    /** @return array<int,array{family:string,curve:ResponseCurve,params:array,n_params:int}> */
    private function candidateFits(array $pts,bool $includePiecewise=false): array
    {
        $out = [];
        foreach (array_merge(['linear', 'log', 'power', 'saturation'],$includePiecewise?['piecewise','quadratic_spline']:[]) as $family) {
            $fit = $this->fitFamily($family, $pts);
            if ($fit !== null) {
                $out[] = $fit;
            }
        }
        return $out;
    }

    /**
     * Best fit for one family: closed-form `a` per grid `b`, keep the least-squares best.
     */
    private function fitFamily(string $family, array $pts): ?array
    {
        if(in_array($family,['piecewise','quadratic_spline'],true))return $this->fitFlexible($pts,$family);
        $support = max(array_map(fn($p) => (float) $p['spend'], $pts));
        $supportMin = min(array_map(fn($p) => (float) $p['spend'], $pts));
        $best = null;
        $grids = match ($family) {
            'linear' => [0.0],
            'log' => self::LOG_B,
            'power' => self::POWER_B,
            'saturation' => self::SAT_B,
        };
        foreach ($grids as $b) {
            if ($family==='power' && ($b<=0 || $b>=1)) {continue;}

            // Basis g_i for the shape; a = sum(g*y)/sum(g^2)
            $sg2 = $sgy = 0.0;
            $ok = true;
            foreach ($pts as $p) {
                $x = (float) $p['spend'];
                $y = (float) $p['response'];
                $g = match ($family) {
                    'linear' => $x,
                    'log' => log1p($b * $x),
                    'power' => pow($x, $b),
                    'saturation' => -expm1(-$b * $x),
                };
                if (!is_finite($g)) {
                    $ok = false;
                    break;
                }
                $sg2 += $g * $g;
                $sgy += $g * $y;
            }
            if (!$ok || $sg2 <= 0) {
                continue;
            }
            $a = $sgy / $sg2;
            if ($a <= 0 || !is_finite($a)) {
                continue;
            }
            $sse = 0.0;
            foreach ($pts as $p) {
                $x = (float) $p['spend'];
                $y = (float) $p['response'];
                $g = match ($family) {
                    'linear' => $x,
                    'log' => log1p($b * $x),
                    'power' => pow($x, $b),
                    'saturation' => -expm1(-$b * $x),
                };
                $sse += ($y - $a * $g) ** 2;
            }
            if ($best === null || $sse < $best['sse']) {
                $curve = match ($family) {
                    'linear' => ResponseCurve::linear($a, $support, $supportMin),
                    'log' => ResponseCurve::log($a, $b, $support, $supportMin),
                    'power' => ResponseCurve::power($a, $b, $support, $supportMin),
                    'saturation' => ResponseCurve::saturation($a, $b, $support, $supportMin),
                };
                $best = ['family' => $family, 'curve' => $curve, 'params' => ['a' => $a, 'b' => $b], 'n_params' => $family === 'linear' ? 1 : 2, 'sse' => $sse];
            }
        }
        return $best;
    }

    /** Fixed knots and decreasing slope ratios, fitted on training only. No interpolation of future response. */
    private function fitFlexible(array $pts,string $family):?array{
        $xs=array_map(fn($p)=>(float)$p['spend'],$pts);sort($xs);$n=count($xs);if($n<5||count(array_unique($xs))<5)return null;$best=null;
        foreach([.25,.5,.75]as$q){$k=$xs[(int)floor(($n-1)*$q)];if($k<=0)continue;
            foreach([0.,.1,.25,.5,.75]as$ratio){$gg=$gy=0.;foreach($pts as$p){$x=(float)$p['spend'];$g=$family==='piecewise'?min($x,$k)+$ratio*max(0,$x-$k):($x<=$k?$x-(1-$ratio)*$x*$x/(2*$k):$k*(1+$ratio)/2+$ratio*($x-$k));$gg+=$g*$g;$gy+=$g*(float)$p['response'];}if($gg<=0)continue;$a=$gy/$gg;if(!is_finite($a)||$a<=0)continue;$curve=$family==='piecewise'?ResponseCurve::piecewise($a,$ratio,$k,max($xs),min($xs)):ResponseCurve::quadraticSpline($a,$ratio,$k,max($xs),min($xs));$ss=0.;foreach($pts as$p)$ss+=($p['response']-$curve->revenueAt($p['spend']))**2;
                if($best===null||$ss<$best['sse'])$best=['family'=>$family,'curve'=>$curve,'params'=>['a'=>$a,'b'=>$ratio,'knot'=>$k],'n_params'=>3,'sse'=>$ss];
            }
        }return $best;
    }

    /** Relative RMSE of a curve on points (vs mean response); null when undefined. */
    private function relRmse(ResponseCurve $curve, array $pts): ?float
    {
        if ($pts === []) {
            return null;
        }
        $mean = array_sum(array_map(fn($p) => (float) $p['response'], $pts)) / count($pts);
        if ($mean <= 0) {
            return null;
        }
        $ss = 0.0;
        foreach ($pts as $p) {
            $ss += ((float) $p['response'] - $curve->revenueAt((float) $p['spend'])) ** 2;
        }
        return sqrt($ss / count($pts)) / $mean;
    }

    /**
     * Instability: refit the family on the first 80% of the training window and
     * measure the largest relative curve deviation at quartiles of the support.
     */
    private function instability(string $family, array $train): float
    {
        $n = count($train);
        $reduced = array_slice($train, 0, max(5, (int) floor($n * 0.8)));
        $full = $this->fitFamily($family, $train);
        $part = $this->fitFamily($family, $reduced);
        if ($full === null || $part === null) {
            return 1.0; // cannot even refit: maximally unstable
        }
        $support = max(array_map(fn($p) => (float) $p['spend'], $train));
        $maxDev = 0.0;
        foreach ([0.25, 0.5, 0.75] as $q) {
            $x = $q * $support;
            $yf = $full['curve']->revenueAt($x);
            $yp = $part['curve']->revenueAt($x);
            $denom = max(abs($yf), 1e-9);
            $maxDev = max($maxDev, abs($yf - $yp) / $denom);
        }
        return $maxDev;
    }

    private function abstain(string $status, string $reason, int $days, array $candidates = []): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'days_used' => $days,
            'candidates' => array_map(fn($c) => [
                'family' => $c['family'],
                'status' => $c['status'] ?? 'rejected',
                'reject_reason' => $c['reject_reason'] ?? null,
                'validation_rmse_rel' => $c['validation_rmse_rel'] ?? null,
                'score' => $c['score'] ?? null,'complexity_penalty'=>$c['complexity_penalty']??null,'instability_penalty'=>$c['instability_penalty']??null,
            ], $candidates),
        ];
    }
}
