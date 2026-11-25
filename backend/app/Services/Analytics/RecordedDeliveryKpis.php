<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ConnectedAdAccount;
use App\Services\Advertising\Sync\RecordedDeliveryRepository;
use Brick\Math\BigDecimal;

/** No mixing original precision with legacy decimals, partial accounts or multiple snapshots per account. */
final class RecordedDeliveryKpis
{
    public function forGroup(int $ownerId, string $provider, string $currency, string $start, string $end): ?array
    {
        if ($provider !== 'google') {
            return null;
        }
        $accounts = ConnectedAdAccount::where('user_id', $ownerId)->where('provider', $provider)->where('currency', $currency)->get();
        if ($accounts->isEmpty()) {
            return null;
        }
        $totals = ['spend' => BigDecimal::zero(), 'impressions' => BigDecimal::zero(), 'clicks' => BigDecimal::zero()];
        $snapshots = [];
        $timezone = null;
        $legacyMissing = false;
        foreach ($accounts as $account) {
            $selection = (new RecordedDeliveryRepository)->selectForAccount($account, $ownerId, $start, $end);
            if ($selection['status'] === 'unavailable_original') {
                $unavailable = RecordedKpiArithmetic::fromTotals([]);
                $unavailable['arithmetic'] = 'unavailable_original_source_integrity';
                $unavailable['source_precision'] = 'original_source_unavailable_no_legacy_substitution';
                $unavailable['reason'] = $selection['reason'];

                return $unavailable;
            }
            if ($selection['status'] !== 'available_original') {
                $legacyMissing = true;

                continue;
            }
            $snapshot = $selection['snapshot'];
            if ($timezone !== null && $timezone !== $snapshot['scope']['timezone']) {
                return null;
            }
            $timezone = $snapshot['scope']['timezone'];
            $snapshots[] = $snapshot['snapshot_id'];
            foreach ($totals as $key => $value) {
                $operand = $snapshot['totals'][$key];
                $totals[$key] = $value === null || $operand === null ? null : $value->plus($operand);
            }
        }
        if ($legacyMissing) {
            if ($snapshots === []) {
                return null;
            }
            $unavailable = RecordedKpiArithmetic::fromTotals([]);
            $unavailable['arithmetic'] = 'unavailable_original_source_integrity';
            $unavailable['source_precision'] = 'mixed_original_and_legacy_accounts_not_combined';
            $unavailable['reason'] = 'complete_selected_account_original_sources_required';

            return $unavailable;
        }
        $result = RecordedKpiArithmetic::fromTotals(array_map(fn ($value) => $value === null ? null : (string) $value, $totals));
        $result['arithmetic'] = 'exact_over_original_reported_delivery_operands';
        $result['source_precision'] = 'original_tokens_preserved_exact_cost_micros_conversion';
        $result['snapshot_ids'] = $snapshots;
        $result['population'] = 'returned_campaign_date_rows_only_not_complete_inventory';
        $result['finality'] = 'retrieval_time_report_not_final_bill';
        $result['outcome_metrics'] = 'unavailable_no_canonical_outcome_equivalence';
        $result['optimization_admission'] = false;

        return $result;
    }
}
