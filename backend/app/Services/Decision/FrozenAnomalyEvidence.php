<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\AnomalyDetector;
use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\RecordedLedger;

/** Retrospective full-window screens, not an online alarm or calibrated hypothesis test. */
final class FrozenAnomalyEvidence
{
    public const POLICY = ['version' => 'frozen-retrospective-screens-v1', 'min_points' => 8, 'iqr_k' => 1.5,
        'robust_z_threshold' => 3.5, 'ewma_lambda' => 0.2, 'ewma_limit' => 3., 'change_point_min_points' => 12, 'change_point_min_z' => 3.];

    public function build(array $ledger): array
    {
        $base = ['snapshot_id' => $ledger['snapshot_id'], 'type' => 'DESCRIPTIVE', 'policy' => self::POLICY,
            'calibration' => 'not_established', 'false_alarm_probability' => null, 'causal_attribution' => null,
            'mode' => 'retrospective_entire_supplied_window_not_online_monitoring',
            'limits' => ['Engineering thresholds are not fitted probabilities, significance levels or sufficient sample guarantees.',
                'IQR and MAD use the entire supplied window. EWMA mean and variance are estimated from that same entire window, not a prior control period; this is retrospective screening, not an online alarm.',
                'Change-point scans one split across the entire window without multiple-testing calibration. Index maps to the first date of the second segment.',
                'Constant or zero-dispersion methods remain degenerate. No flags does not establish healthy performance, completeness or absence of change.',
                'Detectors use approximate binary64 with explicit operand-conversion limits; exact reported operands remain separate. No operational action.']];
        if (($ledger['scope']['provenance_kind'] ?? null) !== 'synthetic_fixture') {
            return $base + ['status' => 'unavailable', 'reason' => 'source_population_not_qualified', 'entities' => []];
        }
        $coverage = (new SuppliedCalendarCoverage)->build($ledger);
        if (($coverage['status'] ?? null) !== 'audited_supplied_calendar_only' || ! $coverage['supplied_grid_complete'] || $coverage['returned_cells'] === 0) {
            return $base + ['status' => 'unavailable', 'reason' => 'complete_declared_supplied_calendar_required', 'entities' => []];
        }
        if ($coverage['declared_window_days'] > 366 || $coverage['returned_cells'] > 3660) {
            return $base + ['status' => 'unavailable', 'reason' => 'retrospective_screen_resource_limit_366_days_3660_cells', 'entities' => []];
        }
        $entities = [];
        foreach ($ledger['rows'] as $row) {
            foreach (RecordedLedger::METRICS as $metric) {
                $entities[$row['variable_id']][$metric][$row['reporting_date']] = $row['metrics'][$metric]['decimal'];
            }
        }
        ksort($entities, SORT_STRING);
        $out = [];
        foreach ($entities as $id => $metrics) {
            foreach ($metrics as $metric => $history) {
                ksort($history, SORT_STRING);
                $values = [];
                $dates = array_keys($history);
                $operands = array_values($history);
                $reason = null;
                foreach ($operands as $operand) {
                    if ($operand === null) {
                        $reason = 'missing_operand_not_zero_filled';
                        break;
                    }
                    $float = (float) $operand;
                    if (! is_finite($float) || $float > 1e12 || Binary64Boundary::loss($operand, $float)->isGreaterThan('0.0001')) {
                        $reason = 'model_numeric_range_or_operand_conversion_loss';
                        break;
                    }
                    $values[] = $float;
                }
                if ($reason !== null) {
                    $out[$id][$metric] = ['status' => 'unavailable', 'reason' => $reason];

                    continue;
                }
                $analysis = (new AnomalyDetector)->analyze($values, self::POLICY);
                foreach (['iqr', 'robust_z', 'ewma'] as $method) {
                    foreach ($analysis[$method]['flags'] as &$flag) {
                        $index = $flag['index'];
                        $flag['date'] = $dates[$index];
                        $flag['operand_recorded_exact'] = $operands[$index];
                    }unset($flag);
                }
                foreach ($analysis['flagged_points'] as &$point) {
                    $point['date'] = $dates[$point['index']];
                    $point['operand_recorded_exact'] = $operands[$point['index']];
                }unset($point);
                $split = $analysis['change_point']['index'] ?? null;
                $analysis['change_point']['date'] = $split === null ? null : $dates[$split];
                $out[$id][$metric] = ['status' => 'screened_retrospectively', 'dates' => $dates, 'operands_recorded_exact' => $operands,
                    'operands_approximate' => $values, 'analysis' => $analysis];
            }
        }

        return $base + ['status' => 'computed_retrospective_screens', 'operand_absolute_conversion_loss_cap' => '0.0001', 'entities' => $out];
    }
}
