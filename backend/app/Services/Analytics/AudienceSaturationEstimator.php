<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Evidence-gated estimate of a frequency level beyond which CTR falls or CPA rises, for ONE entity's daily rows.
 * Candidate models (data selects, none is imposed): constant, linear, and hinge (flat until threshold t, then linear).
 * Selection: weighted leave-one-out error with a fixed relative-improvement margin; the hinge must beat both simpler models.
 * The interval is a deterministic percentile bootstrap over days of the threshold ESTIMATOR. It is not a causal interval, it ignores
 * dependence between consecutive days, and observed daily frequency is not an experiment. Output type is ESTIMATED, never ACTUAL.
 */
final class AudienceSaturationEstimator
{
    public const MIN_DAYS = 21;
    public const MIN_DISTINCT_FREQUENCIES = 8;
    public const MIN_RELATIVE_IMPROVEMENT = 0.10;
    public const BOOTSTRAPS = 400;
    public const MIN_DETERIORATION_SHARE = 0.90;
    public const SEED = 20261124;

    public function estimate(array $days, string $metric): array
    {
        if (! in_array($metric, ['ctr', 'cpa'], true)) {
            return $this->no('invalid_metric', 'Metric must be ctr or cpa.');
        }
        $points = [];
        $last = null;
        foreach ($days as $d) {
            $date = $d['date'] ?? '';
            try {
                $dt = new \DateTimeImmutable((string) $date);
            } catch (\Throwable) {
                return $this->no('invalid_input', 'Invalid daily date.');
            }
            if ($dt->format('Y-m-d') !== $date || ($last !== null && $dt <= $last)) {
                return $this->no('invalid_input', 'Dates must be unique and increasing.');
            }
            $last = $dt;
            foreach (['frequency', 'impressions', 'clicks', 'spend', 'conversions'] as $k) {
                if (! array_key_exists($k, $d) || $d[$k] === null) {
                    continue 2; // unknown day: left out, never treated as zero
                }
                if (! is_numeric($d[$k]) || ! is_finite((float) $d[$k]) || $d[$k] < 0) {
                    return $this->no('invalid_input', 'Negative or non-finite metric.');
                }
            }
            $f = (float) $d['frequency'];
            $imp = (float) $d['impressions'];
            $conv = (float) $d['conversions'];
            if ($f <= 0 || $imp <= 0) {
                continue;
            }
            if ($metric === 'ctr') {
                $points[] = [$f, (float) $d['clicks'] / $imp, $imp];
            } elseif ($conv > 0) {
                $points[] = [$f, (float) $d['spend'] / $conv, $conv];
            }
        }
        $n = count($points);
        if ($n < self::MIN_DAYS) {
            return $this->no('insufficient_data', 'At least '.self::MIN_DAYS.' usable days are required; '.$n.' found. Days with unknown fields are excluded, not zero-filled.', ['usable_days' => $n]);
        }
        $freqs = array_unique(array_map(fn ($p) => round($p[0], 6), $points));
        if (count($freqs) < self::MIN_DISTINCT_FREQUENCIES) {
            return $this->no('insufficient_data', 'Too little variation in frequency to learn any relationship.', ['usable_days' => $n, 'distinct_frequencies' => count($freqs)]);
        }
        $fit = $this->select($points);
        $bad = $metric === 'ctr' ? -1 : 1;
        $base = ['status' => 'ok', 'type' => 'ESTIMATED', 'metric' => $metric, 'usable_days' => $n, 'candidate_loo_error' => $fit['loo'], 'selected_model' => $fit['model'],
            'method' => 'weighted least squares; candidates constant, linear, hinge; leave-one-out selection with a '.(int) (self::MIN_RELATIVE_IMPROVEMENT * 100).'% relative-improvement margin',
            'limits' => ['observational association, not a causal effect of frequency', 'consecutive days are dependent, so the interval is optimistic', 'daily frequency is a platform-reported ratio and is never averaged across entities here', 'no permission to change spend']];
        if ($fit['model'] !== 'hinge') {
            return $base + ['saturation_threshold' => null, 'interval' => null, 'reason' => 'A flat-then-sloped relationship did not beat the simpler models in leave-one-out error, so no threshold is supported.'];
        }
        if ($fit['slope'] * $bad <= 0) {
            return $base + ['saturation_threshold' => null, 'interval' => null, 'reason' => 'The fitted change after the breakpoint is not in the deteriorating direction, so it is not reported as saturation.', 'breakpoint_estimate' => $fit['t'], 'slope_after_breakpoint' => $fit['slope']];
        }
        $seed = self::SEED;
        $ts = [];
        $badCount = 0;
        for ($b = 0; $b < self::BOOTSTRAPS; $b++) {
            $sample = [];
            for ($i = 0; $i < $n; $i++) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                $sample[] = $points[$seed % $n];
            }
            $h = $this->bestHinge($sample);
            if ($h === null) {
                continue;
            }
            $ts[] = $h['t'];
            if ($h['slope'] * $bad > 0) {
                $badCount++;
            }
        }
        $m = count($ts);
        if ($m < (int) (self::BOOTSTRAPS * 0.9)) {
            return $this->no('unstable', 'Too many resamples could not fit a breakpoint.', ['usable_days' => $n]);
        }
        sort($ts);
        $lo = $ts[(int) floor(0.025 * ($m - 1))];
        $hi = $ts[(int) ceil(0.975 * ($m - 1))];
        $share = $badCount / $m;
        if ($share < self::MIN_DETERIORATION_SHARE) {
            return $base + ['saturation_threshold' => null, 'interval' => null, 'reason' => 'The deteriorating direction was not stable across resamples ('.round($share * 100).'% of resamples).', 'breakpoint_estimate' => $fit['t'], 'deterioration_share' => $share];
        }

        return $base + ['saturation_threshold' => $fit['t'], 'interval' => ['low' => $lo, 'high' => $hi, 'level' => 0.95, 'kind' => 'bootstrap_percentile_of_threshold_estimator_not_causal'],
            'slope_after_breakpoint' => $fit['slope'], 'deterioration_share' => $share, 'bootstrap_resamples' => $m, 'seed' => self::SEED,
            'label' => 'ESTIMATED frequency level after which the observed '.strtoupper($metric).' tends to '.($metric === 'ctr' ? 'fall' : 'rise').'; not a proven cause and not a recommendation to change spend.'];
    }

    private function select(array $p): array
    {
        $n = count($p);
        $sse = ['constant' => 0.0, 'linear' => 0.0, 'hinge' => 0.0];
        $hingeFull = $this->bestHinge($p);
        $loo = ['constant' => 0.0, 'linear' => 0.0, 'hinge' => 0.0];
        $wsum = array_sum(array_column($p, 2));
        for ($i = 0; $i < $n; $i++) {
            $train = $p;
            $test = $train[$i];
            unset($train[$i]);
            $train = array_values($train);
            $c = $this->wls($train, fn ($x) => [1.0]);
            $l = $this->wls($train, fn ($x) => [1.0, $x]);
            $h = $this->bestHinge($train);
            $pred = ['constant' => $c[0], 'linear' => $l[0] + $l[1] * $test[0], 'hinge' => $h === null ? $c[0] : $h['a'] + $h['slope'] * max(0.0, $test[0] - $h['t'])];
            foreach ($pred as $k => $v) {
                $loo[$k] += $test[2] * ($test[1] - $v) ** 2 / $wsum;
            }
        }
        $loo = array_map(fn ($v) => sqrt($v), $loo);
        $simple = min($loo['constant'], $loo['linear']);
        $model = ($hingeFull !== null && $loo['hinge'] <= $simple * (1 - self::MIN_RELATIVE_IMPROVEMENT)) ? 'hinge' : ($loo['linear'] < $loo['constant'] ? 'linear' : 'constant');

        return ['model' => $model, 'loo' => $loo, 't' => $hingeFull['t'] ?? null, 'slope' => $hingeFull['slope'] ?? 0.0];
    }

    /** @return array{t:float,a:float,slope:float,sse:float}|null */
    private function bestHinge(array $p): ?array
    {
        $xs = array_values(array_unique(array_map(fn ($q) => $q[0], $p)));
        sort($xs);
        $k = count($xs);
        if ($k < 6) {
            return null;
        }
        $best = null;
        for ($i = (int) floor($k * 0.2); $i <= (int) ceil($k * 0.8) - 1; $i++) {
            $t = $xs[$i];
            $w = $this->wls($p, fn ($x) => [1.0, max(0.0, $x - $t)]);
            if ($w === null) {
                continue;
            }
            $sse = 0.0;
            foreach ($p as $q) {
                $sse += $q[2] * ($q[1] - ($w[0] + $w[1] * max(0.0, $q[0] - $t))) ** 2;
            }
            if ($best === null || $sse < $best['sse']) {
                $best = ['t' => $t, 'a' => $w[0], 'slope' => $w[1], 'sse' => $sse];
            }
        }

        return $best;
    }

    /** Weighted least squares for 1 or 2 regressors via normal equations. */
    private function wls(array $p, \Closure $basis): ?array
    {
        $m = count($basis(0.0));
        $A = array_fill(0, $m, array_fill(0, $m, 0.0));
        $b = array_fill(0, $m, 0.0);
        foreach ($p as $q) {
            $phi = $basis($q[0]);
            for ($i = 0; $i < $m; $i++) {
                $b[$i] += $q[2] * $phi[$i] * $q[1];
                for ($j = 0; $j < $m; $j++) {
                    $A[$i][$j] += $q[2] * $phi[$i] * $phi[$j];
                }
            }
        }
        if ($m === 1) {
            return $A[0][0] > 0 ? [$b[0] / $A[0][0]] : null;
        }
        $det = $A[0][0] * $A[1][1] - $A[0][1] * $A[1][0];
        if (abs($det) < 1e-12 * max(1.0, abs($A[0][0] * $A[1][1]))) {
            return null;
        }

        return [($b[0] * $A[1][1] - $A[0][1] * $b[1]) / $det, ($A[0][0] * $b[1] - $A[1][0] * $b[0]) / $det];
    }

    private function no(string $status, string $reason, array $extra = []): array
    {
        return ['status' => $status, 'type' => 'ESTIMATED', 'saturation_threshold' => null, 'interval' => null, 'reason' => $reason] + $extra;
    }
}
