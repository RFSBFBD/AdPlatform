<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Curves\ResponseCurve;

/**
 * Scenario, Sensitivity and Stability engine (Master Spec sections 30-31, 60-64).
 *
 * Terminology contract (enforced in labels):
 *  - A SCENARIO is a deterministic what-if evaluation on given curves. It is
 *    typed SCENARIO (spec section 12) and never executed (spec section 30).
 *  - SENSITIVITY re-solves the optimization under explicit perturbations and
 *    measures how much the ALLOCATION moves. Stability classes
 *    (stable/moderately_sensitive/highly_sensitive) are deterministic labels
 *    over configured thresholds; they carry NO probability meaning.
 *  - STRESS ranges (best/expected/worst) evaluate one fixed allocation under an
 *    explicit curve-scale uncertainty `u` that comes from the fitted model's
 *    validation error. They are NOT prediction intervals and carry no level.
 */
final class ScenarioEngine
{
    /**
     * Deterministic sensitivity: for each channel, scale its curve output by
     * (1 +/- p) and re-solve; measure the largest shift in any channel's budget
     * SHARE (percentage points). Deterministic; no probability attached.
     *
     * @param array $channels [ ['channel'=>..., 'curve'=>ResponseCurve, 'min'=>float, 'max'=>float] ]
     * @param callable $solver fn(array $channels, float $budget): ?array allocation channel=>spend (null when infeasible)
     */
    public function sensitivity(array $channels, float $budget, callable $solver): array
    {
        $cfg = config('analytics.scenarios');
        $p = ((float) $cfg['perturbation_percent']) / 100.0;

        if(!$channels||!is_finite($budget)||$budget<=0||!is_finite($p)||$p<=0||$p>=1)return ['status'=>'not_computable','stability'=>null,'reason'=>'Positive finite budget and perturbation strictly between0and100percent required.'];
        $base = $solver($channels, $budget);
        if ($base === null) {
            return ['status' => 'not_computable', 'reason' => 'The unperturbed optimization has no feasible solution.'];
        }
        $baseShares = $this->shares($base, $budget);

        $maxShift = 0.0;
        $runs = [];$failed=0;
        foreach ($channels as $i => $ch) {
            foreach ([1 + $p, 1 - $p] as $scale) {
                $perturbed = $channels;
                $perturbed[$i]['curve'] = $this->scaled($ch['curve'], $scale);
                $alloc = $solver($perturbed, $budget);
                if ($alloc === null) {
                    $failed++;$runs[] = ['channel' => $ch['channel'], 'scale' => $scale, 'status' => 'infeasible'];
                    continue;
                }
                $shares = $this->shares($alloc, $budget);
                $shift = 0.0;
                foreach ($baseShares as $name => $bs) {
                    $shift = max($shift, abs(($shares[$name] ?? 0.0) - $bs));
                }
                $runs[] = ['channel' => $ch['channel'], 'scale' => $scale, 'max_share_shift_pp' => round($shift * 100, 2)];
                $maxShift = max($maxShift, $shift);
            }
        }

        $shiftPp = $maxShift * 100;
        $class = $shiftPp < (float) $cfg['stable_below_pp'] ? 'stable'
            : ($shiftPp < (float) $cfg['moderate_below_pp'] ? 'moderately_sensitive' : 'highly_sensitive');

        return [
            'status' => $failed>0?'partial':'ok',
            'reason'=>$failed>0?'At least one perturbation was infeasible or uncertified. Stability is withheld; observed shift is only a lower bound over completed runs.':null,
            'failed_perturbations'=>$failed,
            'perturbation_percent' => (float) $cfg['perturbation_percent'],
            'max_share_shift_pp' => round($shiftPp, 2),
            'stability' => $failed>0?null:$class,
            'thresholds_pp' => ['stable_below' => (float) $cfg['stable_below_pp'], 'moderate_below' => (float) $cfg['moderate_below_pp']],
            'runs' => $runs,
            'label' => 'Deterministic sensitivity to an explicit +/-' . $cfg['perturbation_percent'] . '% curve-scale perturbation. Not a probability, not a confidence statement.',
        ];
    }

    /**
     * Deterministic stress range for a fixed allocation under curve-scale
     * uncertainty u (from the fitted model's validation error; the caller
     * decides and documents the source). No probabilistic reading.
     *
     * @param array $channels like sensitivity() with an added 'validation_rmse_rel' per channel (null in assumed mode)
     * @param array $allocation channel => spend
     */
    public function stressRange(array $channels, array $allocation): array
    {
        $rows = [];
        $usable = true;
        foreach ($channels as $ch) {
            $u = $ch['validation_rmse_rel'] ?? null;
            $spend = (float) ($allocation[$ch['channel']] ?? 0.0);
            $expected = $ch['curve']->revenueAt($spend);
            if ($u === null) {
                $usable = false;
                $rows[] = ['channel' => $ch['channel'], 'spend' => $spend, 'expected' => $expected, 'best' => null, 'worst' => null, 'reason' => 'no fitted-model validation error; an uncertainty width is not invented for an assumed curve'];
                continue;
            }
            $u = min(1.0, max(0.0, (float) $u));
            $rows[] = [
                'channel' => $ch['channel'],
                'spend' => round($spend, 2),
                'expected' => round($expected, 2),
                'best' => round($expected * (1 + $u), 2),
                'worst' => round($expected * (1 - $u), 2),
                'u_source' => 'fitted model validation relative RMSE',
                'u' => round($u, 4),
            ];
        }
        return [
            'status' => $usable ? 'ok' : 'partial',
            'channels' => $rows,
            'label' => 'Deterministic stress range (best/expected/worst) under the stated curve-scale uncertainty. Not a prediction interval; no confidence level applies.',
        ];
    }

    /** Fixed custom allocation, no optimization or execution. */
    public function evaluate(array $channels,array $allocation): array
    {
        $rows=[];$spend=0.0;$value=0.0;
        foreach ($channels as $ch) {
            $x=$allocation[$ch['channel']]??null;
            if ($x===null || !is_numeric($x) || !is_finite((float)$x) || $x<$ch['min']-1e-8 || $x>$ch['max']+1e-8) {
                return ['status'=>'infeasible','reason'=>'Custom allocation violates a channel bound or omits its amount.'];
            }
            $x=(float)$x;$v=$ch['curve']->revenueAt($x);$spend+=$x;$value+=$v;
            $rows[]=['channel'=>$ch['channel'],'spend'=>$x,'modeled_conversion_value'=>$v,'extrapolates_beyond_support'=>$x>$ch['curve']->supportMaxSpend() || (method_exists($ch['curve'],'supportMinSpend') && $x<$ch['curve']->supportMinSpend())];
        }
        return ['status'=>'ok','type'=>'SCENARIO','spend'=>$spend,'modeled_conversion_value'=>$value,'modeled_roas'=>$spend>0?$value/$spend:null,'channels'=>$rows,'stress'=>$this->stressRange($channels,$allocation),'note'=>'Fixed user allocation evaluated under the same fitted/assumed curves. No campaign changes. Pausing is zero budget only when channel minimum allows it. Not a causal forecast.'];
    }

    /** Wrap a curve with a deterministic output scale. */
    private function scaled(ResponseCurve $curve, float $scale): ResponseCurve
    {
        $params = $curve->parameters();
        $a = $params['a'] * $scale;
        $b = $params['b'];
        return match ($curve->name()) {
            'linear' => ResponseCurve::linear($a, $curve->supportMaxSpend(), $curve->supportMinSpend()),
            'log' => ResponseCurve::log($a, $b, $curve->supportMaxSpend(), $curve->supportMinSpend()),
            'power' => ResponseCurve::power($a, $b, $curve->supportMaxSpend(), $curve->supportMinSpend()),
            'piecewise' => ResponseCurve::piecewise($a,$b,$params['knot'],$curve->supportMaxSpend(),$curve->supportMinSpend()),
            'quadratic_spline' => ResponseCurve::quadraticSpline($a,$b,$params['knot'],$curve->supportMaxSpend(),$curve->supportMinSpend()),
            'saturation' => ResponseCurve::saturation($a, $b, $curve->supportMaxSpend(), $curve->supportMinSpend()),
        };
    }

    /** @return array<string,float> channel => share of budget */
    private function shares(array $alloc, float $budget): array
    {
        $out = [];
        $den = $budget > 0 ? $budget : array_sum($alloc);
        foreach ($alloc as $k => $v) {
            $out[$k] = $den > 0 ? $v / $den : 0.0;
        }
        return $out;
    }
}
