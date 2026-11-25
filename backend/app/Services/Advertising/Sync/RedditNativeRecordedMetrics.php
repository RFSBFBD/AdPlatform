<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/**
 * Versioned native extraction of Reddit Ads API v3 report original bytes (data.metrics[] rows, lowercase keys).
 * Official spec (reddit-ads-api-v3.yaml): spend is int64 "microcurrency"; impressions, clicks and purchase counts are int64; every
 * metric may be null. The spec describes conversion_purchase_total_value only as "the total value of the purchase": its unit is NOT
 * stated there, so no scale is applied or asserted. The spec also does not state whether ends_at is inclusive; requested bounds are
 * kept in provenance and rows outside the requested dates are refused. Native tokens only, never an outcome equivalence. Not live-verified.
 */
final class RedditNativeRecordedMetrics
{
    public const FIELDS = ['DATE', 'SPEND', 'CURRENCY', 'IMPRESSIONS', 'CLICKS', 'CONVERSION_PURCHASE_CLICKS', 'CONVERSION_PURCHASE_VIEWS', 'CONVERSION_PURCHASE_TOTAL_VALUE'];

    private const UNITS = [
        'spend' => 'reddit_microcurrency_of_account_currency',
        'impressions' => 'reddit_impressions',
        'clicks' => 'reddit_clicks',
        'conversion_purchase_clicks' => 'reddit_purchase_conversions_click_attributed_unmatched_outcome',
        'conversion_purchase_views' => 'reddit_purchase_conversions_view_attributed_unmatched_outcome',
        'conversion_purchase_total_value' => 'reddit_purchase_total_value_unit_unstated_by_spec',
    ];

    public function extract(string $body, array $provenance): array
    {
        $r = $provenance['request'] ?? [];
        if (($r['api_version'] ?? null) !== 'v3') {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $aid = $provenance['account_external_id'] ?? '';
        $d = $r['body']['data'] ?? null;
        if (($r['endpoint'] ?? null) !== '/ad_accounts/'.$aid.'/reports' || ! is_array($d) || ($d['fields'] ?? null) !== self::FIELDS || ($d['breakdowns'] ?? null) !== ['DATE'] || ($d['time_zone_id'] ?? null) !== 'UTC'
            || array_diff(array_keys($d), ['starts_at', 'ends_at', 'fields', 'breakdowns', 'time_zone_id']) !== []) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! isset($tree['data']['metrics']) || ! is_array($tree['data']['metrics']) || ! array_is_list($tree['data']['metrics'])) {
            throw new InvalidArgumentException('reddit_native_data_metrics_list_required');
        }
        $rows = [];
        $seen = [];
        foreach ($tree['data']['metrics'] as $i => $m) {
            $date = is_array($m) ? ($m['date'] ?? null) : null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ($p = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $p->format('Y-m-d') !== $date
                || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$date]) || ($m['currency'] ?? null) !== ($provenance['currency'] ?? false)) {
                throw new InvalidArgumentException('reddit_native_date_duplicate_range_or_currency');
            }
            $seen[$date] = true;
            foreach (self::UNITS as $k => $unit) {
                $v = $m[$k] ?? null;
                if (is_bool($v) || is_array($v)) {
                    throw new InvalidArgumentException('reddit_native_integer_token_required');
                }
                $rows[] = ['account_external_id' => $aid, 'reporting_date' => $date, 'source_pointer' => '/data/metrics/'.$i.'/'.$k, 'metric' => $k,
                    'native_value' => $v === null ? null : ExactValue::of($v, true)->lexeme, 'unit' => $unit,
                    'currency_candidate' => in_array($k, ['spend', 'conversion_purchase_total_value'], true) ? ($provenance['currency'] ?? null) : null, 'transformation' => 'none',
                    'availability' => $v === null ? 'null_or_omitted_unknown_not_zero' : 'recorded', 'contract' => 'reddit-native-v3-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => 'documented_contract_not_live_verified', 'rows' => $rows, 'next_url' => $tree['pagination']['next_url'] ?? null,
            'requested_bounds' => ['starts_at' => $d['starts_at'] ?? null, 'ends_at' => $d['ends_at'] ?? null, 'ends_at_inclusivity' => 'not_stated_by_spec'],
            'source_definition' => 'Reddit purchase conversion metrics under the account-configured attribution; click- and view-attributed counts kept as separate native rows, never summed here.',
            'population' => 'returned_day_rows_only; absent days are unknown, not zero',
            'money' => 'spend is microcurrency in the account currency candidate; purchase total value unit is not stated by the spec and is not scaled'];
    }
}
