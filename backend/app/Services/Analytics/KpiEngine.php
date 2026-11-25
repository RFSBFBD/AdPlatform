<?php

namespace App\Services\Analytics;

use App\Services\Analytics\DTO\MetricValue;

/**
 * KPI Engine (Master Spec section 11).
 *
 * Every KPI is derived from observed platform-reported totals and nothing else,
 * so every value is typed ACTUAL. A KPI whose denominator is zero is null with a
 * machine-readable reason; no substitute number is produced. Each value carries
 * its lineage: the formula and the exact inputs used (spec section 9).
 *
 * These are descriptions of what happened. They are never forecasts.
 */
final class KpiEngine
{
    /**
     * @return array<string,MetricValue> keyed by metric name
     */
    public static function fromTotals(
        float $spend,
        float $impressions,
        float $clicks,
        float $conversions,
        float $conversionValue,
        ?string $currency = null
    ): array {
        $inputs = [
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'conversion_value' => $conversionValue,
        ];
        $src = 'performance_metrics (platform-reported)';
        $lin = fn(string $formula) => ['source' => $src, 'formula' => $formula, 'inputs' => $inputs, 'currency' => $currency];

        $ratio = function (float $num, float $den, string $numName, string $denName, ?float $scale = null) {
            if ($den <= 0.0) {
                return [null, "undefined: {$denName} is zero"];
            }
            $v = $num / $den;
            return [$scale !== null ? $v * $scale : $v, null];
        };

        [$ctr, $ctrReason] = $ratio($clicks, $impressions, 'clicks', 'impressions');
        [$cpc, $cpcReason] = $ratio($spend, $clicks, 'spend', 'clicks');
        [$cpm, $cpmReason] = $ratio($spend, $impressions, 'spend', 'impressions', 1000.0);
        [$cvr, $cvrReason] = $ratio($conversions, $clicks, 'conversions', 'clicks');
        [$cpa, $cpaReason] = $ratio($spend, $conversions, 'spend', 'conversions');
        [$roas, $roasReason] = $ratio($conversionValue, $spend, 'conversion_value', 'spend');

        $n = (int) $impressions;

        return [
            'spend' => MetricValue::actual($spend, $currency, ['source' => $src, 'formula' => 'reported', 'inputs' => $inputs, 'currency' => $currency], null, $n),
            'impressions' => MetricValue::actual($impressions, 'count', ['source' => $src, 'formula' => 'reported', 'inputs' => $inputs], null, $n),
            'clicks' => MetricValue::actual($clicks, 'count', ['source' => $src, 'formula' => 'reported', 'inputs' => $inputs], null, $n),
            'conversions' => MetricValue::actual($conversions, 'count', ['source' => $src, 'formula' => 'reported (platform-attributed; may overlap across platforms)', 'inputs' => $inputs], null, $n),
            'conversion_value' => MetricValue::actual($conversionValue, $currency, ['source' => $src, 'formula' => 'reported (platform-attributed conversion value, not profit)', 'inputs' => $inputs, 'currency' => $currency], null, $n),
            'ctr' => MetricValue::actual($ctr, 'ratio', $lin('clicks / impressions'), $ctrReason, $n),
            'cpc' => MetricValue::actual($cpc, $currency, $lin('spend / clicks'), $cpcReason, $n),
            'cpm' => MetricValue::actual($cpm, $currency, $lin('spend / impressions * 1000'), $cpmReason, $n),
            'cvr' => MetricValue::actual($cvr, 'ratio', $lin('conversions / clicks (platform-reported conversions; not guaranteed unique or single-counted)'), $cvrReason, $n),
            'cpa' => MetricValue::actual($cpa, $currency, $lin('spend / conversions'), $cpaReason, $n),
            'roas' => MetricValue::actual($roas, 'ratio', $lin('conversion_value / spend (platform-reported value, not profit)'), $roasReason, $n),
        ];
    }
}
