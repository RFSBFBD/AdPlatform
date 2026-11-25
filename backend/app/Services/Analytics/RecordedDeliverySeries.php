<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ConnectedAdAccount;
use App\Services\Advertising\Sync\RecordedDeliveryRepository;
use App\Services\Decision\DecisionSnapshotConsumers;
use Brick\Math\BigDecimal;

/** Original-token daily delivery rows. No complete-population, canonical outcome or zero-omission inference. */
final class RecordedDeliverySeries
{
    public function forWindow(int $ownerId, string $provider, string $start, string $end): ?array
    {
        if ($provider !== 'google') {
            return null;
        }
        $accounts = ConnectedAdAccount::where('user_id', $ownerId)->where('provider', $provider)->get();
        if ($accounts->isEmpty() || $accounts->pluck('currency')->unique()->count() !== 1 || $accounts->pluck('timezone')->unique()->count() !== 1) {
            return null;
        }
        $days = [];
        $snapshots = [];
        $sourceEvidence = [];
        $legacyMissing = false;
        foreach ($accounts as $account) {
            $selection = (new RecordedDeliveryRepository)->selectForAccount($account, $ownerId, $start, $end);
            if ($selection['status'] === 'unavailable_original') {
                return ['status' => 'not_computable', 'type' => 'ACTUAL', 'reason' => $selection['reason'],
                    'points' => [], 'snapshot_ids' => [], 'optimization_admission' => false, 'chart_status' => 'not_drawn_original_source_unavailable'];
            }
            if ($selection['status'] !== 'available_original') {
                $legacyMissing = true;

                continue;
            }
            $snapshot = $selection['snapshot'];
            $snapshots[] = $snapshot['snapshot_id'];
            $sourceEvidence[] = (new DecisionSnapshotConsumers)->build([
                'actual' => $snapshot, 'snapshot_id' => $snapshot['snapshot_id'], 'status' => 'source_only_abstained',
                'problem' => ['objective' => null], 'route' => ['status' => 'unsupported_or_insufficient_evidence', 'reasons' => ['recorded_delivery_population_and_outcomes_not_qualified_for_optimization']],
                'scenarios' => [], 'forecast' => ['status' => 'unavailable', 'calibration' => 'not_established']]);
            foreach ($snapshot['rows'] as $row) {
                $date = $row['reporting_date'];
                foreach (['spend', 'impressions', 'clicks'] as $metric) {
                    $days[$date][$account->id][$metric][] = $row['metrics'][$metric]['decimal'];
                }
            }
        }
        if ($legacyMissing) {
            return $snapshots === [] ? null : ['status' => 'not_computable', 'type' => 'ACTUAL', 'reason' => 'complete_selected_account_original_sources_required', 'points' => [], 'snapshot_ids' => [], 'optimization_admission' => false];
        }
        ksort($days, SORT_STRING);
        $points = [];
        foreach ($days as $date => $accountRows) {
            $totals = ['conversions' => null, 'conversion_value' => null];
            foreach (['spend', 'impressions', 'clicks'] as $metric) {
                $sum = BigDecimal::zero();
                $missing = count($accountRows) !== $accounts->count();
                foreach ($accountRows as $metrics) {
                    foreach ($metrics[$metric] as $value) {
                        if ($value === null) {
                            $missing = true;

                            continue;
                        }$sum = $sum->plus($value);
                    }
                }
                $totals[$metric] = $missing ? null : (string) $sum;
            }
            $arithmetic = RecordedKpiArithmetic::fromTotals($totals);
            $arithmetic['arithmetic'] = 'exact_over_original_reported_delivery_operands';
            $arithmetic['source_precision'] = 'original_tokens_preserved_exact_cost_micros_conversion';
            $points[] = ['date' => $date, 'recorded_arithmetic' => $arithmetic, 'accounts_with_returned_rows' => count($accountRows), 'selected_accounts' => $accounts->count()];
        }

        return ['status' => 'recorded_delivery_rows_only', 'type' => 'ACTUAL', 'provider' => $provider, 'start' => $start, 'end' => $end, 'currency' => $accounts->first()->currency, 'timezone' => $accounts->first()->timezone,
            'snapshot_ids' => $snapshots, 'source_evidence' => $sourceEvidence, 'points' => $points, 'population' => 'returned_campaign_date_rows_only_not_complete_inventory',
            'note' => 'Original reporting tokens with exact cost conversion. No missing account/day/campaign contribution is zero-filled. Outcomes are not mapped to canonical purchases, leads or revenue. These are retrieval-time reported rows, not complete inventory or final billing.',
            'chart_status' => 'not_drawn_no_complete_population_claim', 'optimization_admission' => false];
    }
}
