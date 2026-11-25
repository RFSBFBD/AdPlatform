<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Decision\Accounting\ExactValue;

/** Exact arithmetic over persisted operands, explicitly not restored source precision. */
final class RecordedKpiArithmetic
{
    public static function fromTotals(array $totals): array
    {
        $numbers = [];
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $key) {
            $value = $totals[$key] ?? null;
            if ($value === null) {
                $numbers[$key] = null;

                continue;
            }
            $numbers[$key] = ExactValue::of($value, in_array($key, ['impressions', 'clicks'], true))->decimal();
        }
        $out = [];
        foreach ($numbers as $key => $value) {
            $out[$key] = ['decimal' => $value === null ? null : (string) $value, 'fraction' => null];
        }
        foreach (['ctr' => ['clicks', 'impressions', 1], 'cpc' => ['spend', 'clicks', 1], 'cpm' => ['spend', 'impressions', 1000],
            'cvr' => ['conversions', 'clicks', 1], 'cpa' => ['spend', 'conversions', 1], 'roas' => ['conversion_value', 'spend', 1]] as $key => [$num,$den,$scale]) {
            $reason = $numbers[$num] === null || $numbers[$den] === null ? 'missing_operand' : ($numbers[$den]->isZero() ? 'undefined_zero_denominator' : null);
            $out[$key] = ['decimal' => null, 'fraction' => $reason === null ? ExactValue::fraction($numbers[$num]->multipliedBy($scale)->toBigRational()->dividedBy($numbers[$den])) : null, 'reason' => $reason];
        }

        return ['metrics' => $out, 'arithmetic' => 'exact_over_persisted_operands', 'source_precision' => 'legacy_unverified_may_have_been_rounded_before_storage',
            'source_truth' => 'not_established_by_arithmetic', 'display_rounding' => 'none', 'cross_platform_deduplication' => 'not_established'];
    }
}
