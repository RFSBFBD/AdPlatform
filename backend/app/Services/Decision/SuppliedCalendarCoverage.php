<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;

/** Audits the declared supplied entity-day grid. Never infers omitted provider inventory or zero values. */
final class SuppliedCalendarCoverage
{
    public function build(array $ledger): array
    {
        $window = explode('/', $ledger['scope']['reporting_window']);
        if (count($window) !== 2) {
            return ['status' => 'unavailable', 'reason' => 'valid_declared_window_required'];
        }
        $dates = [];
        foreach ($window as $token) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $token);
            if ($date === false || $date->format('Y-m-d') !== $token) {
                return ['status' => 'unavailable', 'reason' => 'valid_declared_window_required'];
            }
            $dates[] = $date;
        }
        if ($dates[0] > $dates[1] || ($days = (int) $dates[0]->diff($dates[1])->format('%a') + 1) > 3660) {
            return ['status' => 'unavailable', 'reason' => 'ordered_window_with_at_most_3660_days_required'];
        }
        $entities = [];
        foreach ($ledger['rows'] as $row) {
            $id = $row['variable_id'];
            $date = $row['reporting_date'];
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date < $window[0] || $date > $window[1] || isset($entities[$id]['days'][$date])) {
                return ['status' => 'unavailable', 'reason' => 'distinct_in_window_entity_dates_required'];
            }
            $entities[$id]['days'][$date] = true;
            foreach (RecordedLedger::METRICS as $metric) {
                $entities[$id]['missing_operands'][$metric] = ($entities[$id]['missing_operands'][$metric] ?? 0) + ($row['metrics'][$metric]['decimal'] === null ? 1 : 0);
            }
        }
        ksort($entities, SORT_STRING);
        $rows = [];
        $missing = 0;
        foreach ($entities as $id => $entity) {
            $missingDays = $days - count($entity['days']);
            $missing += $missingDays;
            $samples = [];
            for ($d = $dates[0]; $d <= $dates[1] && count($samples) < 20; $d = $d->modify('+1 day')) {
                if (! isset($entity['days'][$d->format('Y-m-d')])) {
                    $samples[] = $d->format('Y-m-d');
                }
            }
            $rows[$id] = ['returned_days' => count($entity['days']), 'declared_window_days' => $days, 'missing_entity_days' => $missingDays,
                'missing_day_examples' => $samples, 'missing_day_examples_truncated' => $missingDays > count($samples), 'missing_operands_within_returned_rows' => $entity['missing_operands']];
        }

        return ['status' => 'audited_supplied_calendar_only', 'snapshot_id' => $ledger['snapshot_id'], 'window' => $ledger['scope']['reporting_window'],
            'timezone' => $ledger['scope']['timezone'], 'supplied_entity_count' => count($rows), 'declared_window_days' => $days,
            'expected_cells_within_supplied_entities' => count($rows) * $days, 'returned_cells' => count($ledger['rows']), 'missing_cells_within_supplied_entities' => $missing,
            'supplied_grid_complete' => $missing === 0, 'entities' => $rows, 'source_inventory_complete' => null,
            'limits' => 'Expected cells refer only to entities present in supplied rows, not all provider entities. Missing entity-days and missing metric operands are separate. Neither is zero-filled. A complete supplied grid does not verify inventory, tracking, attribution or source finality. At most20missing dates per entity are listed.'];
    }
}
