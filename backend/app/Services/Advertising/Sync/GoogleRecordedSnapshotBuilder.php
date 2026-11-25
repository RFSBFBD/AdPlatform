<?php

declare(strict_types=1);

namespace App\Services\Advertising\Sync;

use App\Services\Decision\Accounting\RecordedLedger;
use InvalidArgumentException;

/** Frozen reported-row delivery snapshot. Never a complete inventory, finalized bill or outcome model. */
final class GoogleRecordedSnapshotBuilder
{
    public function build(array $pages, array $context): RecordedLedger
    {
        if ($pages === [] || ! array_is_list($pages) || count($pages) > 100 || ($context['provider'] ?? null) !== 'google'
            || ($context['metadata_marker'] ?? null) !== 'provider-discovery-explicit-1'
            || ($context['roster_status'] ?? null) !== 'complete_local_traversal_not_inventory_verified'
            || ! is_array($context['campaign_ids'] ?? null) || $context['campaign_ids'] === []) {
            throw new InvalidArgumentException('google_recorded_scope_metadata_or_roster_unverified');
        }
        foreach (['account_id', 'account_external_id', 'currency', 'timezone', 'start_date', 'end_date'] as $key) {
            if (! is_string($context[$key] ?? null) || $context[$key] === '') {
                throw new InvalidArgumentException('google_recorded_context_required:'.$key);
            }
        }
        $scope = ['provider' => 'google', 'account_id' => $context['account_id'],
            'source_contract' => 'google-v26-returned-campaign-date-delivery-1', 'currency' => $context['currency'], 'timezone' => $context['timezone'],
            'reporting_window' => $context['start_date'].'/'.$context['end_date'],
            'outcome_definition' => 'unavailable_not_selected_for_canonical_outcome', 'outcome_kind' => 'other_explicit',
            'attribution' => 'delivery_only_no_outcome_attribution_claim', 'population' => 'returned_campaign_date_rows_only_not_complete_inventory',
            'conversion_count_kind' => 'fractional_credit', 'provenance_kind' => 'recorded_source'];
        $roster = $context['campaign_ids'];
        if (! array_is_list($roster) || count(array_unique($roster, SORT_STRING)) !== count($roster)) {
            throw new InvalidArgumentException('google_recorded_roster_identity_invalid');
        }
        foreach ($roster as $id) {
            if (! is_string($id) || ! preg_match('/^[0-9]+$/D', $id)) {
                throw new InvalidArgumentException('google_recorded_roster_identity_invalid');
            }
        }
        sort($roster, SORT_STRING);
        $context['campaign_ids'] = $roster;
        $next = null;
        $tokens = [];
        $rows = [];
        $bytes = 0;
        $manifest = [];
        $nativeExtensions = [];
        foreach ($pages as $index => $page) {
            if (! is_int($index) || ! is_string($page['body'] ?? null) || ! is_array($page['provenance'] ?? null)
                || ! is_string($page['sha256'] ?? null) || ! hash_equals($page['sha256'], hash('sha256', $page['body']))) {
                throw new InvalidArgumentException('google_recorded_page_hash_or_shape');
            }
            $bytes += strlen($page['body']);
            if ($bytes > 16 * 1024 * 1024 || strlen($page['body']) > 8 * 1024 * 1024) {
                throw new InvalidArgumentException('google_recorded_byte_limit');
            }
            $p = $page['provenance'];
            foreach (['account_external_id', 'currency', 'timezone', 'start_date', 'end_date'] as $key) {
                if (($p[$key] ?? null) !== $context[$key]) {
                    throw new InvalidArgumentException('google_recorded_page_scope_mismatch:'.$key);
                }
            }
            $endpoint = '/customers/'.str_replace('-', '', $context['account_external_id']).'/googleAds:search';
            if (($p['request']['endpoint'] ?? null) !== $endpoint || ($p['request']['api_version'] ?? null) !== 'v26'
                || ($p['request']['page_token'] ?? null) !== $next || ($p['status'] ?? null) !== 200
                || ($index > 0 && $next === null)) {
                throw new InvalidArgumentException('google_recorded_request_version_or_traversal_mismatch');
            }
            $retrieved = $p['retrieved_at'] ?? null;
            if (! is_string($retrieved) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $retrieved)) {
                throw new InvalidArgumentException('google_recorded_retrieval_time_required');
            }
            try {
                $parsedRetrieval = new \DateTimeImmutable($retrieved);
            } catch (\Throwable) {
                throw new InvalidArgumentException('google_recorded_retrieval_time_invalid');
            }
            if ($parsedRetrieval->format('Y-m-d') !== substr($retrieved, 0, 10) || $parsedRetrieval->format('H:i:s') !== substr($retrieved, 11, 8)) {
                throw new InvalidArgumentException('google_recorded_retrieval_time_invalid');
            }
            $requestId = $p['request_id'] ?? null;
            if ($requestId !== null && (! is_string($requestId) || strlen($requestId) > 512 || preg_match('/[\x00-\x1f\x7f]/', $requestId))) {
                throw new InvalidArgumentException('google_recorded_request_id_invalid');
            }
            $manifest[] = ['page_index' => $index, 'body_sha256' => $page['sha256'], 'body_bytes' => strlen($page['body']),
                'retrieved_at' => $retrieved, 'request_id' => $requestId, 'status' => $p['status'], 'endpoint' => $endpoint, 'api_version' => $p['request']['api_version'],
                'query' => $p['request']['query'] ?? null, 'incoming_page_token_sha256' => $next === null ? null : hash('sha256', $next)];
            $native = (new GoogleNativeRecordedMetrics)->extract($page['body'], $p);
            if (($native['status'] ?? null) !== 'native_recorded') {
                throw new InvalidArgumentException('google_recorded_native_contract_rejected');
            }
            $next = $native['next_page_token'];
            if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 8192 || isset($tokens[$next]))) {
                throw new InvalidArgumentException('google_recorded_repeated_or_invalid_page_token');
            }
            if ($next !== null) {
                $tokens[$next] = true;
            }
            foreach ($native['rows'] as $operand) {
                if (in_array($operand['metric'], ['conversions', 'conversionsValue'], true)) {
                    $nativeExtensions[] = ['campaign_external_id' => $operand['campaign_external_id'], 'reporting_date' => $operand['reporting_date'], 'metric' => $operand['metric'], 'native_value' => $operand['native_value'], 'unit' => $operand['unit'], 'availability' => $operand['availability'], 'source_body_sha256' => $page['sha256'], 'source_pointer' => $operand['source_pointer'], 'contract' => $operand['contract'], 'canonical_mapping' => 'unavailable_no_equivalence_selected'];
                }

                $campaign = $operand['campaign_external_id'];
                if (! in_array($campaign, $context['campaign_ids'], true)) {
                    throw new InvalidArgumentException('google_recorded_campaign_outside_sync_roster');
                }
                $key = $campaign.'|'.$operand['reporting_date'];
                if (! isset($rows[$key])) {
                    $rows[$key] = ['key' => $key, 'variable_id' => 'campaign:'.$campaign,
                        'reporting_date' => $operand['reporting_date'], 'source_ref' => $page['sha256'].':'.preg_replace('~/metrics/[^/]+$~', '', $operand['source_pointer']),
                        'scope' => $scope, 'spend' => null, 'impressions' => null, 'clicks' => null, 'conversions' => null, 'conversion_value' => null,
                        '_page_index' => $index];
                } elseif ($rows[$key]['_page_index'] !== $index) {
                    throw new InvalidArgumentException('google_recorded_duplicate_cross_page_entity_date');
                }
                $field = match ($operand['metric']) {
                    'costMicros' => 'spend', 'impressions' => 'impressions', 'clicks' => 'clicks', default => null
                };
                if ($field !== null) {
                    $rows[$key][$field] = $field === 'spend' ? $operand['exact_account_currency_candidate'] : $operand['native_value'];
                }
            }
        }
        if ($next !== null) {
            throw new InvalidArgumentException('google_recorded_page_chain_incomplete');
        }
        $scope['native_extensions'] = ['version' => 'google-native-outcomes-unmapped-1', 'metrics' => $nativeExtensions, 'limits' => 'Original configured conversion credits/value, not canonical purchases, leads, unique people or verified revenue. Value denomination is not inferred from cost currency.'];
        $scope['source_lineage'] = ['version' => 'google-returned-row-lineage-1', 'pages' => $manifest,
            'account_external_id' => $context['account_external_id'], 'metadata_evidence_marker' => $context['metadata_marker'],
            'roster_status' => $context['roster_status'], 'roster_campaign_ids' => array_values($context['campaign_ids']),
            'cost_transform' => 'exact_cost_micros_divide_by_1000000', 'cost_transform_source' => 'https://developers.google.com/google-ads/api/docs/experiments/pull-geo-data',
            'original_bytes_location' => 'owned_recorded_reporting_responses_by_body_sha256', 'finality' => 'retrieval_time_report_not_final_bill',
            'identity_assurance' => 'recorded_transport_context_not_independent_provider_reconciliation'];
        foreach ($rows as &$row) {
            unset($row['_page_index']);
            $row['scope'] = $scope;
        }
        unset($row);

        return new RecordedLedger($scope, array_values($rows));
    }
}
