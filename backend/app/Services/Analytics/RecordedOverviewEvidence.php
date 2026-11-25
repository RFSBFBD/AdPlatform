<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;

/** Native-currency persisted operands, not FX-adjusted actuals. Missing values remain unknown. */
final class RecordedOverviewEvidence
{
    public function build(int $ownerId, iterable $rows, string $start, string $end, bool $allProviderAccountsSelected): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->provider.'/'.$row->currency][] = $row;
        }
        ksort($groups, SORT_STRING);
        $out = [];
        foreach ($groups as $key => $group) {
            $problem = (new OutcomeSemanticGuard)->problem($group);
            $totals = [];
            foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $metric) {
                $sum = BigDecimal::zero();
                $missing = $problem !== null && in_array($metric, ['conversions', 'conversion_value'], true);
                foreach ($group as $row) {
                    $raw = $row->getRawOriginal($metric);
                    if ($raw === null) {
                        $missing = true;

                        continue;
                    }$sum = $sum->plus(ExactValue::of($raw, in_array($metric, ['impressions', 'clicks'], true))->decimal());
                }
                $totals[$metric] = $missing ? null : (string) $sum;
            }
            $original = $allProviderAccountsSelected ? (new RecordedDeliveryKpis)->forGroup($ownerId, $group[0]->provider, $group[0]->currency, $start, $end) : null;
            $scopeProblem = $original === null ? (new MetricScopeGuard)->problem($ownerId, $start, $end, $group[0]->provider, null, false, false) : null;
            if ($scopeProblem !== null) {
                $out[$key] = ['provider' => $group[0]->provider, 'currency' => $group[0]->currency, 'status' => 'unavailable', 'reason' => $scopeProblem, 'recorded_arithmetic' => RecordedKpiArithmetic::fromTotals([])];

                continue;
            }
            $out[$key] = ['provider' => $group[0]->provider, 'currency' => $group[0]->currency, 'recorded_arithmetic' => $original ?? RecordedKpiArithmetic::fromTotals($totals)];
        }

        return ['status' => 'native_currency_evidence', 'groups' => $out, 'cross_currency_sum' => null, 'foreign_exchange_applied' => false,
            'limits' => 'Exact native operands only. Legacy source precision may already be lost. Original snapshots describe returned rows, not complete inventory or final billing. No unique outcomes or source truth is established.'];
    }
}
