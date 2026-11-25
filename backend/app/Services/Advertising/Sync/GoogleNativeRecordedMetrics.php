<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/** Versioned native extraction only. Exact cost candidate only. No outcome equivalence or model admission. */
final class GoogleNativeRecordedMetrics
{
    public function extract(string $body, array $provenance): array
    {
        $version = $provenance['request']['api_version'] ?? null;
        if (! in_array($version, ['v21', 'v26'], true)) {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $expected = "SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '".($provenance['start_date'] ?? '')."' AND '".($provenance['end_date'] ?? '')."'";
        if (($provenance['request']['query'] ?? null) !== $expected) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! isset($tree['results']) || ! is_array($tree['results']) || ! array_is_list($tree['results']) || isset($tree['error'])) {
            throw new InvalidArgumentException('google_native_result_list_required');
        }
        $rows = [];
        $seen = [];
        foreach ($tree['results'] as $index => $r) {
            $id = $r['campaign']['id'] ?? null;
            $date = $r['segments']['date'] ?? null;
            if (! is_string($id) || ! preg_match('/^[0-9]+$/D', $id) || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date
                || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$id.'|'.$date])) {
                throw new InvalidArgumentException('google_native_entity_date_duplicate_or_range');
            }
            $seen[$id.'|'.$date] = true;
            foreach (['costMicros' => ['google_cost_micros', true], 'impressions' => ['reported_impressions', true],
                'clicks' => ['reported_clicks', true], 'conversions' => ['included_action_conversion_credits', false],
                'conversionsValue' => ['included_action_conversion_value_native', false]] as $key => [$unit,$integral]) {
                $value = $r['metrics'][$key] ?? null;
                $rows[] = ['campaign_external_id' => $id, 'reporting_date' => $date, 'source_pointer' => '/results/'.$index.'/metrics/'.$key,
                    'metric' => $key, 'native_value' => $value === null ? null : ExactValue::of($value, $integral)->lexeme,
                    'unit' => $unit,
                    'exact_account_currency_candidate' => $key === 'costMicros' && $value !== null ? (string) ExactValue::of($value, true)->decimal()->dividedByExact('1000000') : null,
                    'currency_candidate' => $key === 'costMicros' ? ($provenance['currency'] ?? null) : null,
                    'transformation' => $key === 'costMicros' ? 'exact_divide_by_1000000_documented_no_rounding' : 'none',
                    'availability' => $value === null ? 'omitted_unknown_not_zero' : 'recorded',
                    'contract' => 'google-native-'.$version.'-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => $version === 'v21' ? 'sunset_archival_only' : 'current_documented_contract_not_live_verified', 'rows' => $rows, 'next_page_token' => $tree['nextPageToken'] ?? null,
            'source_definition' => 'Configured included conversion actions, not assumed purchases/leads/unique customers.',
            'population' => 'returned_rows_only; date-segmented all-zero rows can be absent',
            'money_unit_conversion' => 'costMicros_exact_candidate_only_not_canonical_admission',
            'cost_transform_source' => 'https://developers.google.com/google-ads/api/docs/experiments/pull-geo-data'];
    }
}
