<?php

namespace App\Services\Analytics\Statistics;

/**
 * Confidence and prediction interval helpers.
 *
 * Honesty contract (these distinctions are surfaced in the API and the UI):
 *  - A CONFIDENCE interval quantifies uncertainty about an estimated quantity.
 *  - A PREDICTION interval quantifies where a FUTURE observation may land; it is
 *    wider by design and is produced only by the forecasting engine.
 *  - A STRESS range (best/expected/worst) is a deterministic what-if spread under
 *    explicitly stated perturbations. It is NOT a probability statement, carries
 *    no confidence level, and is labelled as such.
 *
 * Wilson is used only where the binomial framing is explicitly stated by the
 * caller (see proportion()). Platform-reported "conversions" are NOT assumed to
 * be Bernoulli trials: attributed and possibly multi-counted events break that
 * model, so ratio metrics like CVR default to the daily-observation interval,
 * which treats complete days as the sampling unit.
 */
final class ConfidenceIntervals
{
    /**
     * Wilson score interval for a binomial proportion.
     *
     * The caller must only use this where "successes out of n independent trials"
     * is a defensible description (e.g. clicks per impression as a rough model,
     * with the caveat returned in `assumption`). If successes > trials the
     * binomial framing is impossible and null is returned with a reason.
     *
     * @return array{estimate:float,lower:float,upper:float,level:float,n:int,assumption:string}|null
     */
    public static function proportion(float $successes, float $trials, float $level = 0.95): ?array
    {
        if (!is_finite($successes) || !is_finite($trials) || $level <= 0 || $level >= 1 || $trials != floor($trials) || $successes != floor($successes) || $trials <= 0 || $successes < 0) {
            return null;
        }
        if ($successes > $trials) {
            return null; // binomial framing impossible (e.g. conversions > clicks)
        }
        $z = Quantiles::normalQuantile(1 - (1 - $level) / 2);
        $p = $successes / $trials;
        $z2 = $z * $z;
        $denom = 1 + $z2 / $trials;
        $centre = ($p + $z2 / (2 * $trials)) / $denom;
        $half = ($z / $denom) * sqrt($p * (1 - $p) / $trials + $z2 / (4 * $trials * $trials));
        return [
            'estimate' => $p,
            'lower' => max(0.0, $centre - $half),
            'upper' => min(1.0, $centre + $half),
            'level' => $level,
            'n' => (int) $trials,
            'assumption' => 'Wilson interval under a stated binomial approximation; platform events are not guaranteed to be independent trials.',
        ];
    }

    /**
     * Interval for the mean of per-day observations (Student-t).
     *
     * Sampling unit = one complete day. Stated limits (returned in `limits`):
     * days are treated as independent, which ignores autocorrelation and the
     * effect of changing spend across days; the interval describes dispersion of
     * the daily mean, not a causal effect.
     *
     * @param float[] $dailyValues
     * @return array{estimate:float,lower:float,upper:float,level:float,n:int,limits:string}|null null when n < $minN or zero variance
     */
    public static function dailyMean(array $dailyValues, float $level = 0.95, int $minN = 7): ?array
    {
        $dailyValues = array_values(array_filter($dailyValues, fn($v) => is_finite((float) $v)));
        $n = count($dailyValues);
        if ($n < $minN) {
            return null;
        }
        $mean = array_sum($dailyValues) / $n;
        $ss = 0.0;
        foreach ($dailyValues as $v) {
            $ss += ($v - $mean) * ($v - $mean);
        }
        $var = $ss / ($n - 1);
        if ($var <= 0.0) {
            return null; // zero dispersion: an interval of width 0 would overstate certainty
        }
        $t = Quantiles::studentTQuantile(1 - (1 - $level) / 2, $n - 1);
        $half = $t * sqrt($var / $n);
        return [
            'estimate' => $mean,
            'lower' => $mean - $half,
            'upper' => $mean + $half,
            'level' => $level,
            'n' => $n,
            'limits' => 't-interval over complete days treated as independent; autocorrelation and spend variation across days are not modelled.',
        ];
    }
}
