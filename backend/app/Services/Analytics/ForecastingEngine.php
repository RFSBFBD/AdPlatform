<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Statistics\Quantiles;

/**
 * Forecasting Engine (Master Spec section 29).
 *
 * Independent of the OR engine. Produces EXPECTED values with PREDICTION
 * intervals (where future observations may land) — never a bare point, and never
 * confused with a confidence interval about an estimate.
 *
 * Two candidate methods, chosen deterministically by holdout validation:
 *  - naive: next value = last observed value (the baseline every model must beat).
 *  - linear trend: OLS on (day index, value).
 * The last `holdout` days are used to compare both by RMSE; the winner is named
 * in the output. If the trend does not beat the naive baseline, the naive
 * forecast is used and the output says so. A forecast is never produced with
 * less than the configured minimum history.
 *
 * Honesty limits (returned in `limits`): days are treated as independent;
 * auction dynamics, seasonality beyond a linear trend, and platform changes are
 * not modelled. A prediction interval is not a promise (spec: no 100% future).
 */
final class ForecastingEngine
{
    /**
     * @param float[] $series chronological complete-day values
     * @param int $horizon days ahead to forecast
     */
    public function forecast(array $series, int $horizon = 7, ?float $level = null): array
    {
        $cfg = config('analytics.forecast');
        $minDays = (int) $cfg['min_days'];
        $level = $level ?? (float) $cfg['default_level'];

        if (!is_finite($level) || $level<=0 || $level>=1 || count(array_filter($series,fn($v)=>!is_numeric($v) || !is_finite((float)$v)))>0) {
            return ['status'=>'invalid_data','reason'=>'A finite numeric value for every complete day and an interval level in (0,1) are required. Invalid days are not removed.','points'=>[]];
        }
        $values = array_values(array_map('floatval', array_filter($series, fn($v) => is_finite((float) $v))));
        $n = count($values);
        if ($n < $minDays) {
            return [
                'status' => 'insufficient_data',
                'reason' => "Forecasting needs at least {$minDays} complete days; got {$n}. No number was produced.",
                'min_days' => $minDays,
                'points' => [],
            ];
        }
        $horizon = max(1, min($horizon, 30));

        $holdout = max(3, (int) floor($n * 0.25));
        $trainN = $n - $holdout;

        // Validate candidates on the holdout.
        $train = array_slice($values, 0, $trainN);
        $test = array_slice($values, $trainN);
        $naiveErr = $this->rmse(array_fill(0, count($test), (float) $train[$trainN - 1]), $test);
        $trendFit = $this->ols($train);
        $trendPred = [];
        foreach ($test as $i => $y) {
            $trendPred[] = $trendFit['intercept'] + $trendFit['slope'] * ($trainN + $i);
        }
        $trendErr = $this->rmse($trendPred, $test);

        $useTrend = $trendErr < $naiveErr && $trendFit !== null;

        // Fit the winner on the FULL series.
        $fullFit = $this->ols($values);
        $points = [];
        if ($useTrend && $fullFit !== null) {
            $s = $fullFit['residual_sd'];
            $df = max(1, $n - 2);
            $t = Quantiles::studentTQuantile(1 - (1 - $level) / 2, $df);
            for ($h = 1; $h <= $horizon; $h++) {
                $x = $n - 1 + $h;
                $yhat = $fullFit['intercept'] + $fullFit['slope'] * $x;
                // Prediction interval: s * t * sqrt(1 + 1/n + (x-xbar)^2/Sxx)
                $half = $s > 0
                    ? $t * $s * sqrt(1 + 1 / $n + (($x - $fullFit['xbar']) ** 2) / $fullFit['sxx'])
                    : null;
                $points[] = $this->point($yhat, $half, $s > 0 ? null : 'zero_residual_variance');
            }
            $method = 'linear_trend';
            $params = ['slope' => $fullFit['slope'], 'intercept' => $fullFit['intercept'], 'residual_sd' => $fullFit['residual_sd']];
        } else {
            // Naive: interval from the dispersion of day-over-day changes.
            $diffs = [];
            for ($i = 1; $i < $n; $i++) {
                $diffs[] = $values[$i] - $values[$i - 1];
            }
            $sd = $this->sd($diffs);
            $t = Quantiles::studentTQuantile(1 - (1 - $level) / 2, max(1, count($diffs) - 1));
            for ($h = 1; $h <= $horizon; $h++) {
                $half = $sd > 0 ? $t * $sd * sqrt($h) : null;
                $points[] = $this->point($values[$n - 1], $half, $sd > 0 ? null : 'zero_diff_variance');
            }
            $method = 'naive_last_value';
            $params = ['last_value' => $values[$n - 1], 'diff_sd' => $sd];
        }

        return [
            'status' => 'ok',
            'method' => $method,
            'method_selection' => [
                'candidates' => ['naive_last_value', 'linear_trend'],
                'validation' => 'chronological_holdout',
                'holdout_days' => $holdout,
                'naive_rmse' => round($naiveErr, 6),
                'trend_rmse' => round($trendErr, 6),
                'selected' => $method,
            ],
            'level' => $level,
            'interval_kind' => 'prediction',
            'horizon_days' => $horizon,
            'history_days' => $n,
            'params' => $params,
            'points' => $points,
            'limits' => 'Prediction intervals on complete-day history treated as independent; auction dynamics, seasonality beyond a linear trend, and platform/reporting changes are not modelled. An interval is not a promise about the future.',
        ];
    }

    private function point(float $yhat, ?float $half, ?string $reason): array
    {
        return [
            'expected' => round($yhat, 4),
            'lower' => $half !== null ? round($yhat - $half, 4) : null,
            'upper' => $half !== null ? round($yhat + $half, 4) : null,
            'interval_reason' => $reason,
        ];
    }

    /** OLS on (0..n-1, y). Returns null when the design is degenerate. */
    private function ols(array $y): ?array
    {
        $n = count($y);
        if ($n < 3) {
            return null;
        }
        $xbar = ($n - 1) / 2;
        $ybar = array_sum($y) / $n;
        $sxx = $sxy = 0.0;
        foreach ($y as $x => $v) {
            $sxx += ($x - $xbar) ** 2;
            $sxy += ($x - $xbar) * ($v - $ybar);
        }
        if ($sxx <= 0) {
            return null;
        }
        $slope = $sxy / $sxx;
        $intercept = $ybar - $slope * $xbar;
        $ss = 0.0;
        foreach ($y as $x => $v) {
            $r = $v - ($intercept + $slope * $x);
            $ss += $r * $r;
        }
        return ['slope' => $slope, 'intercept' => $intercept, 'residual_sd' => sqrt($ss / max(1, $n - 2)), 'xbar' => $xbar, 'sxx' => $sxx];
    }

    private function rmse(array $pred, array $actual): float
    {
        $n = count($actual);
        if ($n === 0) {
            return INF;
        }
        $ss = 0.0;
        foreach ($actual as $i => $y) {
            $ss += (($pred[$i] ?? 0) - $y) ** 2;
        }
        return sqrt($ss / $n);
    }

    private function sd(array $v): float
    {
        $n = count($v);
        if ($n < 2) {
            return 0.0;
        }
        $m = array_sum($v) / $n;
        $ss = 0.0;
        foreach ($v as $x) {
            $ss += ($x - $m) ** 2;
        }
        return sqrt($ss / ($n - 1));
    }
}
