<?php

namespace App\Services\Analytics;

/**
 * Anomaly Detection (Master Spec section 38).
 *
 * Four deterministic techniques, each with its own minimum-history gate; no
 * single algorithm is forced on every series. Each flag names the method, the
 * point, and the threshold crossed. A series shorter than a method's gate
 * returns 'insufficient_history' for that method, never a guessed verdict.
 *
 * Sampling unit = one complete day. All methods assume the series order is the
 * time order given by the caller.
 */
final class AnomalyDetector
{
    /**
     * @param float[] $series chronological complete-day values; invalid input abstains
     * @return array per-method results + a compact list of flagged points
     */
    public function analyze(array $series, ?array $policy = null): array
    {
        if (count(array_filter($series,fn($v)=>!is_numeric($v) || !is_finite((float)$v)))>0) { return ['status'=>'invalid_data','reason'=>'Every day requires a finite numeric value. Invalid observations are not dropped or renumbered.','flagged_points'=>[]]; }
        $series=array_values($series);
        $cfg = $policy ?? config('analytics.anomaly');
        $clean = [];
        foreach ($series as $i => $v) {
            if (is_finite((float) $v)) {
                $clean[] = ['index' => $i, 'value' => (float) $v];
            }
        }
        $values = array_column($clean, 'value');
        $n = count($values);

        $minPts = (int) $cfg['min_points'];
        $result = [
            'n' => $n,
            'iqr' => $n >= $minPts ? $this->iqr($values, (float) $cfg['iqr_k']) : ['status' => 'insufficient_history', 'required' => $minPts, 'flags' => []],
            'robust_z' => $n >= $minPts ? $this->robustZ($values, (float) $cfg['robust_z_threshold']) : ['status' => 'insufficient_history', 'required' => $minPts, 'flags' => []],
            'ewma' => $n >= $minPts ? $this->ewma($values, (float) $cfg['ewma_lambda'], (float) $cfg['ewma_limit']) : ['status' => 'insufficient_history', 'required' => $minPts, 'flags' => []],
            'change_point' => $n >= (int) $cfg['change_point_min_points']
                ? $this->changePoint($values, (float) $cfg['change_point_min_z'])
                : ['status' => 'insufficient_history', 'required' => (int) $cfg['change_point_min_points'], 'detected' => false],
        ];

        $flagged = [];
        foreach (['iqr', 'robust_z', 'ewma'] as $m) {
            foreach ($result[$m]['flags'] ?? [] as $f) {
                $flagged[$f['index']][] = $m;
            }
        }
        $result['flagged_points'] = collect($flagged)->map(fn($methods, $idx) => ['index' => (int) $idx, 'methods' => $methods, 'value' => $series[$idx]])->values()->all();
        $result['note'] = 'Flags mark unusual points for investigation, not proven problems; attribution/reporting lag can produce them.';

        return $result;
    }

    /** IQR fences (Tukey). */
    private function iqr(array $v, float $k): array
    {
        [$q1, $q3] = [$this->percentile($v, 25), $this->percentile($v, 75)];
        $iqr = $q3 - $q1;
        if ($iqr <= 0.0) {
            return ['status' => 'degenerate', 'detail' => 'IQR is zero (series nearly constant); fence detection not applicable.', 'flags' => []];
        }
        [$lo, $hi] = [$q1 - $k * $iqr, $q3 + $k * $iqr];
        $flags = [];
        foreach ($v as $i => $x) {
            if ($x < $lo || $x > $hi) {
                $flags[] = ['index' => $i, 'value' => $x, 'fence' => $x < $lo ? 'lower' : 'upper', 'fence_value' => $x < $lo ? $lo : $hi];
            }
        }
        return ['status' => 'ok', 'q1' => $q1, 'q3' => $q3, 'k' => $k, 'lower_fence' => $lo, 'upper_fence' => $hi, 'flags' => $flags];
    }

    /** Robust z-score: |x - median| / (1.4826 * MAD). */
    private function robustZ(array $v, float $threshold): array
    {
        $median = $this->percentile($v, 50);
        $devs = array_map(fn($x) => abs($x - $median), $v);
        $mad = $this->percentile($devs, 50);
        if ($mad <= 0.0) {
            return ['status' => 'degenerate', 'detail' => 'MAD is zero (series nearly constant); robust z-score not applicable.', 'flags' => []];
        }
        $flags = [];
        foreach ($v as $i => $x) {
            $z = abs($x - $median) / (1.4826 * $mad);
            if ($z > $threshold) {
                $flags[] = ['index' => $i, 'value' => $x, 'z' => round($z, 3), 'threshold' => $threshold];
            }
        }
        return ['status' => 'ok', 'median' => $median, 'mad' => $mad, 'threshold' => $threshold, 'flags' => $flags];
    }

    /** EWMA control chart with exact time-varying limits. */
    private function ewma(array $v, float $lambda, float $limit): array
    {
        $mu0 = array_sum($v) / count($v);
        $var = 0.0;
        foreach ($v as $x) {
            $var += ($x - $mu0) * ($x - $mu0);
        }
        $sigma = sqrt($var / max(1, count($v) - 1));
        if ($sigma <= 0.0) {
            return ['status' => 'degenerate', 'detail' => 'Zero variance; EWMA not applicable.', 'flags' => []];
        }
        $z = $mu0;
        $flags = [];
        $factorBase = $lambda / (2 - $lambda);
        foreach ($v as $t => $x) {
            $z = $lambda * $x + (1 - $lambda) * $z;
            $halfWidth = $limit * $sigma * sqrt($factorBase * (1 - pow(1 - $lambda, 2 * ($t + 1))));
            if ($z > $mu0 + $halfWidth || $z < $mu0 - $halfWidth) {
                $flags[] = ['index' => $t, 'value' => $x, 'ewma' => round($z, 4), 'center' => round($mu0, 4), 'half_width' => round($halfWidth, 4)];
            }
        }
        return ['status' => 'ok', 'lambda' => $lambda, 'limit' => $limit, 'center' => $mu0, 'sigma' => $sigma, 'flags' => $flags];
    }

    /**
     * Single change-point scan: the split t that maximises the standardised
     * difference of the two segment means (pooled-SD z). Reported only when the
     * best split clears the configured z. This detects ONE level shift; it is not
     * a multiple-change-point method.
     */
    private function changePoint(array $v, float $minZ): array
    {
        $n = count($v);
        $best = null;
        for ($t = 2; $t <= $n - 2; $t++) {
            $a = array_slice($v, 0, $t);
            $b = array_slice($v, $t);
            [$ma, $sa] = $this->meanSd($a);
            [$mb, $sb] = $this->meanSd($b);
            $pooled = sqrt((($t - 1) * $sa * $sa + ($n - $t - 1) * $sb * $sb) / max(1, $n - 2));
            if ($pooled <= 0.0) {
                continue;
            }
            $z = abs($ma - $mb) / ($pooled * sqrt(1 / $t + 1 / ($n - $t)));
            if ($best === null || $z > $best['z']) {
                $best = ['index' => $t, 'z' => $z, 'mean_before' => $ma, 'mean_after' => $mb];
            }
        }
        if ($best === null) {
            return ['status' => 'degenerate', 'detail' => 'Zero pooled variance.', 'detected' => false];
        }
        $detected = $best['z'] >= $minZ;
        return [
            'status' => 'ok',
            'detected' => $detected,
            'index' => $detected ? $best['index'] : null,
            'z' => round($best['z'], 3),
            'min_z' => $minZ,
            'mean_before' => round($best['mean_before'], 4),
            'mean_after' => round($best['mean_after'], 4),
            'direction' => $best['mean_after'] > $best['mean_before'] ? 'up' : 'down',
            'detail' => 'Single-shift scan; a detected point marks where the mean level changed, not why.',
        ];
    }

    /** @return array{0:float,1:float} mean and sample sd */
    private function meanSd(array $v): array
    {
        $n = count($v);
        $m = array_sum($v) / $n;
        if ($n < 2) {
            return [$m, 0.0];
        }
        $ss = 0.0;
        foreach ($v as $x) {
            $ss += ($x - $m) * ($x - $m);
        }
        return [$m, sqrt($ss / ($n - 1))];
    }

    /** Linear-interpolation percentile (type 7, same as R/numpy default). */
    private function percentile(array $v, float $p): float
    {
        sort($v);
        $n = count($v);
        if ($n === 1) {
            return $v[0];
        }
        $h = ($p / 100) * ($n - 1);
        $lo = (int) floor($h);
        $hi = min($n - 1, $lo + 1);
        return $v[$lo] + ($h - $lo) * ($v[$hi] - $v[$lo]);
    }
}
