<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use InvalidArgumentException;

/**
 * Versioned native extraction of Meta Insights original bytes. Native tokens only.
 * Documented at https://developers.facebook.com/docs/marketing-api/reference/ad-campaign/insights/ (spend, impressions,
 * clicks, time_increment) and https://developers.facebook.com/docs/marketing-api/reference/ads-action-stats/ (action `value`
 * is the default-attribution-window metric, per-window keys only when requested). Not live-verified.
 * Every action type is preserved as its own native row; no action is declared a purchase/lead or equivalent to another
 * provider's outcome, and spend is documented as an ESTIMATED metric in account currency.
 */
final class MetaNativeRecordedMetrics
{
    public const FIELDS = 'campaign_id,campaign_name,spend,impressions,reach,frequency,clicks,actions,action_values';

    public function extract(string $body, array $provenance): array
    {
        $version = $provenance['request']['api_version'] ?? null;
        if (! is_string($version) || ! preg_match('/^v\d{2}\.\d$/D', $version)) {
            return ['status' => 'unsupported_version', 'rows' => []];
        }
        $r = $provenance['request'];
        if (($r['endpoint'] ?? null) !== '/'.($r['act_id'] ?? '').'/insights' || ($r['level'] ?? null) !== 'campaign' || ($r['fields'] ?? null) !== self::FIELDS
            || ($r['time_increment'] ?? null) !== 1 || ($r['time_range'] ?? null) !== ['since' => $provenance['start_date'] ?? null, 'until' => $provenance['end_date'] ?? null]
            || array_key_exists('breakdowns', $r) || array_key_exists('action_attribution_windows', $r) || array_key_exists('action_breakdowns', $r)) {
            return ['status' => 'unsupported_query_shape', 'rows' => []];
        }
        $tree = RecordedJson::decode($body);
        if (! isset($tree['data']) || ! is_array($tree['data']) || ! array_is_list($tree['data']) || isset($tree['error'])) {
            throw new InvalidArgumentException('meta_native_data_list_required');
        }
        $rows = [];
        $seen = [];
        foreach ($tree['data'] as $index => $d) {
            $id = $d['campaign_id'] ?? null;
            $date = $d['date_start'] ?? null;
            if (! is_array($d) || ! is_string($id) || ! preg_match('/^[0-9]+$/D', $id) || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date
                || ($d['date_stop'] ?? $date) !== $date || $date < $provenance['start_date'] || $date > $provenance['end_date'] || isset($seen[$id.'|'.$date])) {
                throw new InvalidArgumentException('meta_native_entity_date_duplicate_or_range');
            }
            $seen[$id.'|'.$date] = true;
            foreach (['spend' => ['meta_spend_account_currency_estimated', false], 'impressions' => ['reported_impressions', true], 'clicks' => ['reported_clicks_all_click_types', true]] as $key => [$unit, $integral]) {
                $value = $d[$key] ?? null;
                $rows[] = $this->row($id, $date, '/data/'.$index.'/'.$key, $key, $value === null ? null : ExactValue::of($value, $integral)->lexeme, $unit, $version, $key === 'spend' ? ($provenance['currency'] ?? null) : null);
            }
            foreach (['actions', 'action_values'] as $listKey) {
                $list = $d[$listKey] ?? null;
                if ($list === null) {
                    $rows[] = $this->row($id, $date, '/data/'.$index.'/'.$listKey, $listKey, null, 'provider_action_list_omitted', $version, null);

                    continue;
                }
                if (! is_array($list) || ! array_is_list($list)) {
                    throw new InvalidArgumentException('meta_native_action_list_malformed');
                }
                $types = [];
                foreach ($list as $j => $action) {
                    $type = is_array($action) ? ($action['action_type'] ?? null) : null;
                    if (! is_string($type) || $type === '' || strlen($type) > 255 || isset($types[$type]) || ! array_key_exists('value', $action)) {
                        throw new InvalidArgumentException('meta_native_action_descriptor_malformed_or_duplicate');
                    }
                    $types[$type] = true;
                    $rows[] = $this->row($id, $date, '/data/'.$index.'/'.$listKey.'/'.$j.'/value', $listKey.':'.$type, ExactValue::of($action['value'])->lexeme,
                        $listKey === 'actions' ? 'provider_default_window_action_count_unmatched_outcome' : 'provider_default_window_action_value_native_currency_unconfirmed', $version, null);
                }
            }
        }

        return ['status' => 'native_recorded', 'api_lifecycle' => 'documented_contract_not_live_verified', 'rows' => $rows, 'next_page_url' => $tree['paging']['next'] ?? null,
            'source_definition' => 'Provider default attribution setting as returned. Action types preserved individually, none declared a purchase, lead or cross-provider outcome.',
            'population' => 'returned_rows_only; absent campaign/days are unknown, not zero',
            'money' => 'spend is a documented ESTIMATED metric string in the account currency candidate; action_values currency is not asserted'];
    }

    private function row(string $id, string $date, string $pointer, string $metric, ?string $native, string $unit, string $version, ?string $currency): array
    {
        return ['campaign_external_id' => $id, 'reporting_date' => $date, 'source_pointer' => $pointer, 'metric' => $metric, 'native_value' => $native, 'unit' => $unit,
            'currency_candidate' => $currency, 'transformation' => 'none', 'availability' => $native === null ? 'omitted_unknown_not_zero' : 'recorded',
            'contract' => 'meta-native-'.$version.'-lexical-1', 'admission_state' => 'native_recorded_not_canonical_qualified'];
    }
}
