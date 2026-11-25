<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/**
 * Versioned native extraction of Spotify Ads API v3 aggregate_reports original bytes (rows[].stats[] = {field_type, field_value}).
 * Official OpenAPI 3.0.0: field_value is a number; conversion counts are presented as -5 when the true count is above 0 and below 5
 * (privacy sentinel: preserved as suppressed, never negative, never zero). SPEND is already in the ad account billing currency
 * (Spotify's published API behavior note), not micro-units. DAY report_end covers the whole UTC end date per the OpenAPI.
 * Native tokens only; no outcome equivalence. Not live-verified, and test accounts never produce report events.
 */
final class SpotifyNativeRecordedMetrics
{
    public const FIELDS = ['IMPRESSIONS', 'CLICKS', 'SPEND', 'PURCHASES', 'REVENUE'];

    private const UNITS = [
        'IMPRESSIONS' => ['spotify_impressions', true],
        'CLICKS' => ['spotify_clicks', true],
        'SPEND' => ['spotify_billing_currency_not_micro', false],
        'PURCHASES' => ['spotify_purchase_conversions_provider_reported_unmatched_outcome', false],
        'REVENUE' => ['spotify_conversion_revenue_provider_reported_not_verified_sales', false],
    ];

    public function extract(string $body, array $provenance): array
    {
        $r = $provenance['request'] ?? [];
        if (($r['api_version'] ?? null) !== 'v3') {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $aid = $provenance['account_external_id'] ?? '';
        if (($r['endpoint'] ?? null) !== '/ad_accounts/'.$aid.'/aggregate_reports' || ($r['entity_type'] ?? null) !== 'AD_ACCOUNT' || ($r['granularity'] ?? null) !== 'DAY' || ($r['fields'] ?? null) !== self::FIELDS
            || ! is_string($r['report_start'] ?? null) || ! is_string($r['report_end'] ?? null)) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! isset($tree['rows']) || ! is_array($tree['rows']) || ! array_is_list($tree['rows']) || isset($tree['error'])) {
            throw new InvalidArgumentException('spotify_native_rows_list_required');
        }
        $out = [];
        $seen = [];
        foreach ($tree['rows'] as $i => $row) {
            $start = is_array($row) ? ($row['start_time'] ?? null) : null;
            $date = is_string($start) ? substr($start, 0, 10) : '';
            if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00Z$/D', $start) || ($p = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $p->format('Y-m-d') !== $date
                || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$date]) || ($row['entity_type'] ?? null) !== 'AD_ACCOUNT' || ($row['entity_id'] ?? null) !== $aid
                || ! isset($row['stats']) || ! is_array($row['stats']) || ! array_is_list($row['stats'])) {
                throw new InvalidArgumentException('spotify_native_day_duplicate_range_or_entity');
            }
            $seen[$date] = true;
            $stats = [];
            foreach ($row['stats'] as $j => $st) {
                $type = is_array($st) ? ($st['field_type'] ?? null) : null;
                if (! is_string($type) || ! isset(self::UNITS[$type]) || isset($stats[$type]) || ! array_key_exists('field_value', $st) || $st['field_value'] === null) {
                    throw new InvalidArgumentException('spotify_native_stat_malformed_unrequested_or_duplicate');
                }
                $stats[$type] = [$j, $st['field_value']];
            }
            foreach (self::UNITS as $type => [$unit, $integral]) {
                $j = $stats[$type][0] ?? null;
                $v = $stats[$type][1] ?? null;
                $suppressed = false;
                $lexeme = null;
                if ($v !== null) {
                    if (in_array($type, ['PURCHASES', 'REVENUE'], true) && (string) $v === '-5') {
                        $suppressed = true;
                        $lexeme = '-5';
                    } else {
                        $lexeme = ExactValue::of($v, $integral)->lexeme;
                    }
                }
                $out[] = ['account_external_id' => $aid, 'reporting_date' => $date, 'source_pointer' => '/rows/'.$i.'/stats/'.($j ?? 'absent').'/field_value', 'metric' => $type,
                    'native_value' => $suppressed ? null : $lexeme, 'unit' => $unit, 'currency_candidate' => in_array($type, ['SPEND', 'REVENUE'], true) ? ($provenance['currency'] ?? null) : null,
                    'transformation' => 'none', 'availability' => $suppressed ? 'suppressed_privacy_sentinel_between_1_and_4_not_zero' : ($v === null ? 'omitted_unknown_not_zero' : 'recorded'),
                    'native_sentinel' => $suppressed ? '-5' : null, 'contract' => 'spotify-native-v3-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => 'documented_contract_not_live_verified', 'rows' => $out, 'continuation_token_present' => ! empty($tree['continuation_token']), 'warnings_present' => ! empty($tree['warnings']),
            'source_definition' => 'Spotify aggregate report stats as returned; purchase counts and revenue are provider-reported instrumentation values, not verified sales.',
            'population' => 'returned_day_rows_only; absent days are unknown, not zero; an empty report from a test account proves nothing',
            'money' => 'SPEND and REVENUE are in the billing currency candidate, not micro-units'];
    }
}
