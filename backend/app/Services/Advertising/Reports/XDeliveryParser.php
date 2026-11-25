<?php

namespace App\Services\Advertising\Reports;

use RuntimeException;

final class XDeliveryParser
{
    public function parse(array $j, array $ids, string $start, string $end, string $timezone, string $currency): array
    {
        $days = [];
        $d = new \DateTimeImmutable($start, new \DateTimeZone($timezone));
        $stop = new \DateTimeImmutable($end, new \DateTimeZone($timezone));
        for (; $d <= $stop; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }
        if (($j['data_type'] ?? null) !== 'stats' || ($j['time_series_length'] ?? null) !== count($days) || ! isset($j['data']) || ! is_array($j['data'])) {
            throw new RuntimeException('X stats time-series length/shape mismatch.');
        }
        $seen = [];
        $out = [];
        foreach ($j['data'] as $entity) {
            $id = $entity['id'] ?? null;
            if (! in_array($id, $ids, true) || isset($seen[$id])) {
                throw new RuntimeException('X unexpected/duplicate entity.');
            }$seen[$id] = true;
            $segments = $entity['id_data'] ?? [];
            if (count($segments) !== 1 || ($segments[0]['segment'] ?? null) !== null) {
                throw new RuntimeException('X segmented stats cannot be silently pooled.');
            }$m = $segments[0]['metrics'] ?? [];
            foreach (['impressions', 'clicks', 'billed_charge_local_micro'] as $key) {
                if (! isset($m[$key]) || ! is_array($m[$key]) || count($m[$key]) !== count($days)) {
                    throw new RuntimeException('X missing/null metric or array-length mismatch.');
                }foreach ($m[$key] as $v) {
                    if (! is_int($v) || $v < 0 || $v > 9007199254740991) {
                        throw new RuntimeException('X delivery metrics must be nonnegative integers; null not zero.');
                    }
                }
            }
            foreach ($days as $i => $day) {
                $out[] = ['type' => 'ACTUAL', 'campaign_id' => $id, 'date' => $day, 'timezone' => $timezone, 'currency' => $currency, 'spend' => $m['billed_charge_local_micro'][$i] / 1e6, 'spend_exact' => (string) \Brick\Math\BigDecimal::of($m['billed_charge_local_micro'][$i])->withPointMovedLeft(6), 'spend_micros' => $m['billed_charge_local_micro'][$i], 'impressions' => $m['impressions'][$i], 'aggregate_clicks' => $m['clicks'][$i], 'conversions' => null, 'revenue' => null, 'placement' => 'ALL_ON_TWITTER'];
            }
        }
        if (array_diff($ids, array_keys($seen))) {
            throw new RuntimeException('X omitted requested entities; absence not measured zero.');
        }

return $out;
    }
}
