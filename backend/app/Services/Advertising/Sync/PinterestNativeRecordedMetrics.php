<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/**
 * Versioned native extraction of Pinterest v5 ad-account analytics original bytes (top-level JSON array of day rows).
 * Documented in the official OpenAPI (info.version 5.32.0) GET /ad_accounts/{id}/analytics and the metrics glossary:
 * micro-dollar columns are in the advertiser profile currency, one million micro-units per currency unit. Not live-verified.
 * Native tokens only: no division by 1e6, no outcome equivalence. TOTAL_CHECKOUT is a Pinterest Tag event count under the
 * requested attribution windows, not a sale, order or cross-provider purchase.
 */
final class PinterestNativeRecordedMetrics
{
    public const COLUMNS = 'SPEND_IN_MICRO_DOLLAR,PAID_IMPRESSION,OUTBOUND_CLICK_1,TOTAL_CHECKOUT,TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR';

    private const UNITS = [
        'SPEND_IN_MICRO_DOLLAR' => ['pinterest_micro_units_of_advertiser_currency', false],
        'PAID_IMPRESSION' => ['pinterest_paid_impressions', true],
        'OUTBOUND_CLICK_1' => ['pinterest_outbound_clicks', true],
        'TOTAL_CHECKOUT' => ['pinterest_tag_checkout_events_under_requested_windows_unmatched_outcome', false],
        'TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR' => ['pinterest_tag_checkout_value_micro_units_event_supplied_not_sales', false],
    ];

    public function extract(string $body, array $provenance): array
    {
        $version = $provenance['request']['api_version'] ?? null;
        if ($version !== 'v5') {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $r = $provenance['request'];
        $aid = $provenance['account_external_id'] ?? '';
        if (($r['endpoint'] ?? null) !== '/ad_accounts/'.$aid.'/analytics' || ($r['columns'] ?? null) !== self::COLUMNS || ($r['granularity'] ?? null) !== 'DAY'
            || ($r['reporting_timezone'] ?? null) !== 'AD_ACCOUNT_TIME_ZONE' || ($r['start_date'] ?? null) !== ($provenance['start_date'] ?? 0) || ($r['end_date'] ?? null) !== ($provenance['end_date'] ?? 0)
            || ! is_int($r['click_window_days'] ?? null) || ! is_int($r['engagement_window_days'] ?? null) || ! is_int($r['view_window_days'] ?? null)
            || ! in_array($r['conversion_report_time'] ?? null, ['TIME_OF_AD_ACTION', 'TIME_OF_CONVERSION'], true)) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! is_array($tree) || ! array_is_list($tree)) {
            throw new InvalidArgumentException('pinterest_native_row_array_required');
        }
        $rows = [];
        $seen = [];
        foreach ($tree as $index => $d) {
            $date = is_array($d) ? ($d['DATE'] ?? null) : null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ($p = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $p->format('Y-m-d') !== $date
                || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$date]) || ($d['AD_ACCOUNT_ID'] ?? null) !== $aid) {
                throw new InvalidArgumentException('pinterest_native_date_duplicate_range_or_account');
            }
            $seen[$date] = true;
            foreach (self::UNITS as $col => [$unit, $integral]) {
                $v = $d[$col] ?? null;
                $rows[] = ['account_external_id' => $aid, 'reporting_date' => $date, 'source_pointer' => '/'.$index.'/'.$col, 'metric' => $col,
                    'native_value' => $v === null ? null : ExactValue::of($v, $integral)->lexeme, 'unit' => $unit,
                    'currency_candidate' => str_contains($col, 'MICRO_DOLLAR') ? ($provenance['currency'] ?? null) : null, 'transformation' => 'none',
                    'availability' => $v === null ? 'omitted_unknown_not_zero' : 'recorded',
                    'contract' => 'pinterest-native-v5-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => 'documented_contract_not_live_verified', 'rows' => $rows,
            'attribution' => ['click_window_days' => $r['click_window_days'], 'engagement_window_days' => $r['engagement_window_days'], 'view_window_days' => $r['view_window_days'], 'conversion_report_time' => $r['conversion_report_time']],
            'source_definition' => 'Pinterest Tag conversion metrics under the requested windows. Micro-unit columns are native tokens; scale is 1,000,000 micro-units per advertiser currency unit and is not applied here.',
            'population' => 'returned_day_rows_only; absent days are unknown, not zero',
            'money' => 'micro-unit columns are in the advertiser profile currency candidate; event-supplied conversion value is not sales revenue'];
    }
}
