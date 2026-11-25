<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;

/** Independent rolling-origin forecast evaluation. No claimed target probability or calibrated interval. */
final class RollingForecastEvidence
{
    public function evaluate(RecordedLedger $ledger, string $metric, int $horizon): array
    {
        if (! in_array($metric, RecordedLedger::METRICS, true) || $horizon < 1 || $horizon > 30) {
            return ['status' => 'unsupported_metric_or_horizon'];
        }
        $l = $ledger->jsonSerialize();
        if ($l['scope']['provenance_kind'] !== 'synthetic_fixture') {
            return ['status' => 'unavailable', 'snapshot_id' => $l['snapshot_id'], 'reason' => 'recorded_source_population_not_qualified_for_forecast', 'points' => [], 'calibration' => 'not_established'];
        }
        $daily = [];
        $entityDays = [];
        foreach ($l['rows'] as $row) {
            $date = $row['reporting_date'];
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date
                || isset($entityDays[$row['variable_id']][$date])) {
                return ['status' => 'invalid_calendar_or_duplicate_day'];
            }
            if (($row['metrics'][$metric]['decimal'] ?? null) === null) {
                return ['status' => 'required_forecast_operand_missing'];
            }
            $entityDays[$row['variable_id']][$date] = true;
            $daily[$date] = ($daily[$date] ?? BigDecimal::zero())->plus($row['metrics'][$metric]['decimal']);
        }
        ksort($daily, SORT_STRING);
        $dates = array_keys($daily);
        if ($dates === [] || $l['scope']['reporting_window'] !== $dates[0].'/'.$dates[count($dates) - 1]) {
            return ['status' => 'unavailable', 'snapshot_id' => $l['snapshot_id'], 'reason' => 'forecast_calendar_must_match_declared_source_window', 'points' => [], 'calibration' => 'not_established'];
        }
        foreach ($entityDays as $days) {
            if (count($days) !== count($dates) || array_diff($dates, array_keys($days))) {
                return ['status' => 'incomplete_entity_day_population'];
            }
        }
        for ($i = 1; $i < count($dates); $i++) {
            if ((new \DateTimeImmutable($dates[$i - 1]))->modify('+1 day')->format('Y-m-d') !== $dates[$i]) {
                return ['status' => 'calendar_gaps'];
            }
        }
        $values = [];
        foreach ($daily as $n) {
            $f = (float) (string) $n;
            if (! is_finite($f) || $f > 1e12 || Binary64Boundary::loss((string) $n, $f)->isGreaterThan('0.0001')) {
                return ['status' => 'forecast_operand_range_or_precision_loss'];
            }
            $values[] = $f;
        }
        $n = count($values);
        if ($n > 366) {
            return ['status' => 'forecast_history_resource_limit', 'required_maximum_days' => 366];
        }
        $initial = 14;
        if ($n < $initial + $horizon + 3) {
            return ['status' => 'insufficient_rolling_history', 'history_days' => $n,
                'required_days' => $initial + $horizon + 3, 'reason' => 'At least4completed rolling origins at the requested horizon are required by engineering policy, not a calibration guarantee.'];
        }
        $errors = ['naive_last_value' => [], 'linear_trend_nonnegative' => [], 'weekly_seasonal_naive' => []];
        $leadErrors = ['naive_last_value' => [], 'linear_trend_nonnegative' => [], 'weekly_seasonal_naive' => []];
        $origins = [];
        for ($origin = $initial; $origin + $horizon <= $n; $origin++) {
            $train = array_slice($values, 0, $origin);
            $actual = array_slice($values, $origin, $horizon);
            $candidates = $this->predict($train, $horizon);
            foreach ($candidates as $name => $prediction) {
                $squared = 0.0;
                foreach ($actual as $h => $observed) {
                    $error = $prediction[$h] - $observed;
                    $squared += $error ** 2;
                    $leadErrors[$name][$h + 1][] = $error;
                }
                $errors[$name][] = $squared / $horizon;
            }
            $origins[] = ['train_through' => $dates[$origin - 1], 'test_from' => $dates[$origin],
                'test_through' => $dates[$origin + $horizon - 1], 'predictions' => $candidates, 'actual_approximate' => $actual,
                'actual_recorded_exact' => array_map(fn ($date) => (string) $daily[$date], array_slice($dates, $origin, $horizon))];
        }
        $rmse = [];
        foreach ($errors as $name => $e) {
            $rmse[$name] = sqrt(array_sum($e) / count($e));
        }
        $byLead = [];
        foreach ($leadErrors as $candidate => $leads) {
            foreach ($leads as $lead => $errorsAtLead) {
                $count = count($errorsAtLead);
                $byLead[$candidate][] = ['lead_days' => $lead, 'evaluated_origins' => $count,
                    'rmse_approximate' => sqrt(array_sum(array_map(fn ($e) => $e ** 2, $errorsAtLead)) / $count),
                    'mae_approximate' => array_sum(array_map('abs', $errorsAtLead)) / $count,
                    'bias_prediction_minus_actual_approximate' => array_sum($errorsAtLead) / $count];
            }
        }
        $selected = 'naive_last_value';
        foreach (['linear_trend_nonnegative', 'weekly_seasonal_naive'] as $candidate) {
            if ($rmse[$candidate] < $rmse[$selected]) {
                $selected = $candidate;
            }
        }
        $forecast = $this->predict($values, $horizon)[$selected];
        $points = [];
        foreach ($forecast as $i => $number) {
            $points[] = ['type' => 'EXPECTED', 'date' => (new \DateTimeImmutable(end($dates)))->modify('+'.($i + 1).' days')->format('Y-m-d'),
                'expected_approximate' => $number, 'lower' => null, 'upper' => null, 'interval_kind' => 'unavailable',
                'interval_reason' => 'No held-out coverage-calibrated interval is available. Rolling residual error is not a prediction interval.'];
        }

        return ['status' => 'evaluated_uncalibrated_forecast', 'snapshot_id' => $l['snapshot_id'], 'metric' => $metric,
            'prequential_selection_evaluation' => $this->prequential($origins), 'evaluation_by_lead' => $byLead, 'history_days' => $n, 'evaluated_origins' => count($origins),
            'source_window' => $l['scope']['reporting_window'], 'timezone' => $l['scope']['timezone'],
            'selection_policy' => 'lowest_pooled_rolling_RMSE_tie_order_last_value_trend_weekly', 'error_sign' => 'prediction_minus_actual',
            'horizon_days' => $horizon, 'selected' => $selected, 'rmse_approximate' => $rmse, 'origins' => $origins,
            'policy' => 'rolling14-min4-origins-three-candidates-v2', 'points' => $points, 'probability_of_success' => null,
            'calibration' => 'not_established', 'limits' => ['Overlapping rolling origins are not independent samples.',
                'Candidate comparison is internal evaluation, not an untouched final test or future calibration.',
                'Nonnegative trend is clipped at zero and evaluated with that same clipping. Weekly seasonal naive repeats the last seven training days recursively, not future observations. Seven-day periodicity is a candidate assumption, not detected seasonality or a proven stable mechanism. Auction/regime mechanisms are absent.',
                'Forecasts are independent of budget optimization and do not establish causal response.']];
    }

    /** Select using completed prior origin outcomes only, then score the untouched next origin. */
    private function prequential(array $origins): array
    {
        $evaluated = [];
        $order = ['naive_last_value', 'linear_trend_nonnegative', 'weekly_seasonal_naive'];
        $leadErrors = [];
        foreach ($origins as $index => $origin) {
            $prior = array_filter(array_slice($origins, 0, $index), fn ($old) => $old['test_through'] <= $origin['train_through']);
            if (count($prior) < 4) {
                continue;
            }
            $rmse = [];
            foreach ($order as $candidate) {
                $sum = 0.;
                $count = 0;
                foreach ($prior as $old) {
                    foreach ($old['actual_approximate'] as $h => $value) {
                        $sum += ($old['predictions'][$candidate][$h] - $value) ** 2;
                        $count++;
                    }
                }
                $rmse[$candidate] = sqrt($sum / $count);
            }
            $selected = $order[0];
            foreach (array_slice($order, 1) as $candidate) {
                if ($rmse[$candidate] < $rmse[$selected]) {
                    $selected = $candidate;
                }
            }
            $prediction = $origin['predictions'][$selected];
            foreach ($origin['actual_approximate'] as $h => $actual) {
                $leadErrors[$h + 1][] = $prediction[$h] - $actual;
            }
            $evaluated[] = ['train_through' => $origin['train_through'], 'test_from' => $origin['test_from'], 'test_through' => $origin['test_through'],
                'completed_prior_origins' => count($prior), 'selection_outcomes_through' => max(array_column($prior, 'test_through')),
                'selected' => $selected, 'prior_rmse_approximate' => $rmse, 'predictions_approximate' => $prediction,
                'actual_recorded_exact' => $origin['actual_recorded_exact']];
        }
        $metrics = [];
        foreach ($leadErrors as $lead => $errors) {
            $count = count($errors);
            $metrics[] = ['lead_days' => $lead, 'evaluated_origins' => $count,
                'rmse_approximate' => sqrt(array_sum(array_map(fn ($e) => $e ** 2, $errors)) / $count),
                'mae_approximate' => array_sum(array_map('abs', $errors)) / $count,
                'bias_prediction_minus_actual_approximate' => array_sum($errors) / $count];
        }

        return ['status' => $evaluated === [] ? 'insufficient_completed_prior_origins' : 'evaluated_adaptive_selection_on_later_origins',
            'policy' => 'at_least_four_fully_completed_prior_origins_only_stable_candidate_tie_order_v1', 'origins' => $evaluated, 'evaluation_by_lead' => $metrics,
            'calibration' => 'not_established', 'limits' => 'Each selection uses only prior origins whose entire test horizon ended by that training cutoff. This evaluates the adaptive selection procedure, not an independent untouched test of the final full-history selected candidate. Later evaluation windows can overlap; no independent-sample, calibrated interval or future guarantee.'];
    }

    private function predict(array $train, int $horizon): array
    {
        $n = count($train);
        $mean = array_sum($train) / $n;
        $mid = ($n - 1) / 2;
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($train as $i => $v) {
            $sxy += ($i - $mid) * ($v - $mean);
            $sxx += ($i - $mid) ** 2;
        }
        $slope = $sxy / $sxx;
        $trend = [];
        for ($h = 1; $h <= $horizon; $h++) {
            $trend[] = max(0.0, $mean + $slope * ($n - 1 + $h - $mid));
        }

        $weekly = [];
        for ($h = 0; $h < $horizon; $h++) {
            $weekly[] = $train[$n - 7 + $h % 7];
        }

        return ['naive_last_value' => array_fill(0, $horizon, $train[$n - 1]), 'linear_trend_nonnegative' => $trend, 'weekly_seasonal_naive' => $weekly];
    }
}
