<?php

namespace App\Services\Analytics;

/** Sustained descriptive signal, not creative quality or a causal diagnosis. */
final class CreativeFatigueEngine
{
    public function analyze(array $days): array
    {
        $no = fn ($reason) => ['status' => 'insufficient_data', 'signal' => null, 'reason' => $reason];
        if (count($days) !== 21) {
            return $no('Exactly21 consecutive complete days for the same entity required.');
        }
        $last = null;
        foreach ($days as $row) {
            $date = $row['date'] ?? '';
            try {
                $d = new \DateTimeImmutable($date);
            } catch (\Throwable) {
                return $no('Invalid daily date.');
            }if ($d->format('Y-m-d') !== $date || ($last !== null && $d != $last->modify('+1 day'))) {
                return $no('Date sequence must be strict consecutive calendar days.');
            }$last = $d;
            foreach (['spend', 'impressions', 'clicks', 'conversions', 'frequency'] as $k) {
                if (! isset($row[$k]) || ! is_numeric($row[$k]) || ! is_finite((float) $row[$k]) || $row[$k] < 0) {
                    return $no('Missing or invalid spend, impressions, clicks, conversions or daily frequency.');
                }
            }
        }
        $windows = [];
        foreach (array_chunk($days, 7) as $chunk) {
            $sum = fn ($k) => array_sum(array_column($chunk, $k));
            $imp = $sum('impressions');
            $clicks = $sum('clicks');
            $conv = $sum('conversions');
            $spend = $sum('spend');
            if ($imp <= 0 || $clicks <= 0 || $conv <= 0) {
                return $no('Each7-day window needs positive impressions, clicks and conversions for comparable ratios.');
            }$windows[] = ['start' => $chunk[0]['date'], 'end' => $chunk[6]['date'], 'spend' => $spend, 'impressions' => $imp, 'clicks' => $clicks, 'conversions' => $conv, 'mean_daily_frequency' => $sum('frequency') / 7, 'ctr' => $clicks / $imp, 'cpm' => $spend / $imp * 1000, 'cpa' => $spend / $conv, 'cvr' => $conv / $clicks];
        }
        // Engineering guard, not a confidence level or learned saturation threshold.
        $band = (float) config('analytics.fatigue_change_fraction', .05);
        if (! is_finite($band) || $band <= 0 || $band >= 1) {
            return $no('Configured fatigue change fraction must be between0and1.');
        }
        $comparisons = [];
        $sustained = true;
        for ($i = 1; $i < 3; $i++) {
            $a = $windows[$i - 1];
            $b = $windows[$i];
            $changes = [];
            foreach (['mean_daily_frequency', 'ctr', 'cpa', 'cpm', 'cvr'] as $k) {
                $changes[$k] = $a[$k] > 0 ? ($b[$k] - $a[$k]) / $a[$k] : null;
            }
            $flag = $changes['mean_daily_frequency'] !== null && $changes['ctr'] !== null && $changes['cpa'] !== null && $changes['mean_daily_frequency'] >= $band && $changes['ctr'] <= -$band && $changes['cpa'] >= $band;
            $sustained = $sustained && $flag;
            $comparisons[] = ['from_window' => $i - 1, 'to_window' => $i, 'fractional_changes' => $changes, 'pattern_present' => $flag];
        }

        return ['status' => 'ok', 'type' => 'OBSERVED_SIGNAL', 'signal' => $sustained ? 'creative_fatigue_pattern' : 'no_sustained_pattern', 'windows' => $windows, 'comparisons' => $comparisons, 'engineering_change_fraction' => $band, 'confidence_level' => null, 'saturation_threshold' => null, 'checks' => $sustained ? ['Inspect creative rotation and audience overlap.', 'Check placement/device/geo mix, auction cost, landing page and conversion tracking.'] : [], 'limits' => ['Two consecutive week-to-week changes require frequency up, CTR down and CPA up beyond the configured engineering band.', 'Frequency is mean DAILY platform frequency at this exact entity, not deduplicated21-day frequency and not additive reach.', 'Ad identity is a proxy, not a canonical creative. A pattern does not prove fatigue or bad creative. No causal claim, calibrated significance, saturation threshold or threshold confidence interval.', 'Recent attributed conversions can restate. Reported conversion counts need not be unique people.']];
    }
}
