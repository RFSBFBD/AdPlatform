<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/**
 * Versioned native extraction of Apple Ads Platform API v1 POST /reports/apps/campaigns/query original bytes.
 * Checked against developer.apple.com apple-ads-platform-api docs (Get-app-campaign-reports, AppsMetrics, Money, TimeRange, ReportingMoney):
 * localSpend is a Money object {amount: decimal string, currency}; impressions/taps/totalInstalls are int64; a single-day request omits
 * granularity and returns totalMetrics only; with DAILY granularity each granularMetrics item carries date YYYY-MM-DD. timeZone UTC is supported.
 * An empty result is returned as an empty object. Installs are app installs, not purchases or revenue. Not live-verified.
 */
final class AppleNativeRecordedMetrics
{
    public const FIELDS = ['localSpend', 'impressions', 'taps', 'totalInstalls'];

    private const UNITS = [
        'localSpend' => ['apple_local_spend_money_decimal_string_account_currency', false],
        'impressions' => ['apple_impressions', true],
        'taps' => ['apple_taps', true],
        'totalInstalls' => ['apple_total_installs_tap_and_view_not_purchases', true],
    ];

    public function extract(string $body, array $provenance): array
    {
        $r = $provenance['request'] ?? [];
        $t = $r['timeRange'] ?? null;
        if (($r['api_version'] ?? null) !== 'v1') {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $aid = $provenance['account_external_id'] ?? '';
        if (($r['endpoint'] ?? null) !== '/reports/apps/campaigns/query' || ! is_array($t) || ($t['timeZone'] ?? null) !== 'UTC' || ! in_array($t['granularity'] ?? null, [null, 'DAILY'], true)
            || ! is_string($t['start'] ?? null) || ! is_string($t['end'] ?? null) || ($t['granularity'] ?? null) === null && $t['start'] !== $t['end']) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! isset($tree['result']) || ! is_array($tree['result']) || ! empty($tree['error'])) {
            throw new InvalidArgumentException('apple_native_result_required');
        }
        $rows = $tree['result']['rows'] ?? [];   // Apple returns an empty object when nothing matches.
        if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
            throw new InvalidArgumentException('apple_native_rows_list_required');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $meta = is_array($row) ? ($row['metadata'] ?? null) : null;
            $id = is_array($meta) ? ($meta['id'] ?? null) : null;
            if (! is_array($meta) || $id === null || ! is_scalar($id) || (string) ($meta['adAccountId'] ?? '') !== $aid || array_key_exists('deviceClass', $meta) || array_key_exists('countryOrRegion', $meta)) {
                throw new InvalidArgumentException('apple_native_campaign_or_account_mismatch');
            }
            $parts = ($t['granularity'] ?? null) === null ? [['/rows/'.$i.'/totalMetrics', $row['totalMetrics'] ?? null, $t['start']]] : [];
            if ($parts === []) {
                if (! isset($row['granularMetrics']) || ! is_array($row['granularMetrics']) || ! array_is_list($row['granularMetrics'])) {
                    throw new InvalidArgumentException('apple_native_granular_missing');
                }
                foreach ($row['granularMetrics'] as $k => $g) {
                    $parts[] = ['/rows/'.$i.'/granularMetrics/'.$k, $g, is_array($g) ? ($g['date'] ?? null) : null];
                }
            }
            foreach ($parts as [$ptr, $m, $date]) {
                $key = $id.'|'.$date;
                if (! is_array($m) || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ($p = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $p->format('Y-m-d') !== $date
                    || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$key])) {
                    throw new InvalidArgumentException('apple_native_day_duplicate_or_range');
                }
                $seen[$key] = true;
                foreach (self::UNITS as $name => [$unit, $integral]) {
                    $v = $m[$name] ?? null;
                    $lexeme = null;
                    if ($name === 'localSpend') {
                        if ($v !== null && (! is_array($v) || ! isset($v['amount'], $v['currency']) || ! is_string($v['amount']) || $v['currency'] !== ($provenance['currency'] ?? null))) {
                            throw new InvalidArgumentException('apple_native_money_shape_or_currency');
                        }
                        $v = $v['amount'] ?? null;
                    }
                    if ($v !== null) {
                        $lexeme = ExactValue::of($v, $integral)->lexeme;
                    }
                    $out[] = ['account_external_id' => $aid, 'campaign_id' => (string) $id, 'reporting_date' => $date, 'source_pointer' => $ptr.'/'.$name.($name === 'localSpend' ? '/amount' : ''), 'metric' => $name,
                        'native_value' => $lexeme, 'unit' => $unit, 'currency_candidate' => $name === 'localSpend' ? ($provenance['currency'] ?? null) : null, 'transformation' => 'none',
                        'availability' => $v === null ? 'omitted_unknown_not_zero' : 'recorded', 'contract' => 'apple-platform-v1-native-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
                }
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => 'documented_contract_not_live_verified', 'rows' => $out, 'total_count_present' => isset($tree['pagination']['totalCount']),
            'source_definition' => 'Apple App Store campaign report metrics as returned. Installs are app installs (tap and view attribution), not purchases or revenue.',
            'population' => 'returned_campaign_day_rows_only; absent campaign-days are unknown, not zero',
            'timezone' => 'UTC as requested; the default ORTZ is not used'];
    }
}
