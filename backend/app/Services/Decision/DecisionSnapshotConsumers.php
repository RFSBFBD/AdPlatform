<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\RecordedKpiArithmetic;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/** Read-only consumers of one frozen result. No fresh metrics, numeric prose generation or external AI call. */
final class DecisionSnapshotConsumers
{
    public function build(array $result): array
    {
        $l = $result['actual'];
        $snapshot = $l['snapshot_id'];
        $daily = [];
        $entities = [];
        $dates = [];
        foreach ($l['rows'] as $key => $row) {
            $date = $row['reporting_date'];
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date
                || isset($dates[$row['variable_id']][$date])) {
                return ['status' => 'unavailable', 'snapshot_id' => $snapshot, 'reason' => 'distinct_valid_entity_dates_required'];
            }
            $dates[$row['variable_id']][$date] = true;
            foreach (RecordedLedger::METRICS as $metric) {
                $value = $row['metrics'][$metric]['decimal'];
                $daily[$date][$metric][] = $value;
                $entities[$row['variable_id']][$date][$metric] = $value;
            }
        }
        ksort($daily, SORT_STRING);
        $points = [];
        $sourcePopulationQualified = ($l['scope']['provenance_kind'] ?? null) === 'synthetic_fixture';
        $populationComplete = true;
        $allDates = array_keys($daily);
        if ($allDates === [] || $l['scope']['reporting_window'] !== $allDates[0].'/'.$allDates[count($allDates) - 1]) {
            $populationComplete = false;
        }
        foreach ($dates as $entityDates) {
            if (count($entityDates) !== count($allDates)) {
                $populationComplete = false;
            }
        }
        for ($i = 1; $i < count($allDates); $i++) {
            if ((new \DateTimeImmutable($allDates[$i - 1]))->modify('+1 day')->format('Y-m-d') !== $allDates[$i]) {
                $populationComplete = false;
            }
        }
        foreach ($daily as $date => $metrics) {
            $totals = [];
            foreach ($metrics as $metric => $values) {
                $totals[$metric] = count($values) !== count($dates) ? null : $this->sum($values);
            }
            $points[] = ['date' => $date, 'type' => 'ACTUAL', 'snapshot_id' => $snapshot, 'totals' => $totals,
                'ratios' => RecordedKpiArithmetic::fromTotals($totals)['metrics']];
        }
        $diagnostics = [];
        foreach ($entities as $id => $history) {
            ksort($history, SORT_STRING);
            $entityDays = array_keys($history);
            $count = count($history);
            if (! $sourcePopulationQualified || ! $populationComplete || $count < 4) {
                $diagnostics[$id] = ['status' => 'insufficient_data', 'health' => 'Insufficient Data', 'reason' => $sourcePopulationQualified ? 'complete_calendar_and_at_least_four_days_required_by_comparison_policy' : 'source_population_not_qualified'];

                continue;
            }
            // Equal-length disjoint adjacent windows. An unmatched earliest day is disclosed, not silently pooled.
            $length = intdiv($count, 2);
            $baselineDays = array_slice($entityDays, $count - 2 * $length, $length);
            $currentDays = array_slice($entityDays, -$length);
            $windows = [];
            foreach (['baseline' => $baselineDays, 'current' => $currentDays] as $label => $windowDates) {
                $totals = [];
                foreach (RecordedLedger::METRICS as $metric) {
                    $totals[$metric] = $this->sum(array_map(fn ($date) => $history[$date][$metric], $windowDates));
                }
                $windows[$label] = ['from' => $windowDates[0], 'through' => end($windowDates), 'totals' => $totals, 'metrics' => RecordedKpiArithmetic::fromTotals($totals)['metrics']];
            }
            $signals = [];
            foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'ctr', 'cpc', 'cpm', 'cvr', 'cpa', 'roas'] as $metric) {
                $base = $this->number($windows['baseline']['metrics'][$metric]);
                $now = $this->number($windows['current']['metrics'][$metric]);
                $signals[$metric] = ['status' => $base === null || $now === null ? 'unavailable' : 'descriptive_comparison',
                    'direction' => $base === null || $now === null ? null : match ($now->compareTo($base)) {
                        -1 => 'down',0 => 'unchanged',1 => 'up'
                    },
                    'change_fraction' => $base === null || $now === null || $base->isZero() ? null : $this->fraction($now->minus($base)->dividedBy($base)),
                    'zero_baseline' => $base?->isZero(), 'statistical_significance' => null, 'causal_attribution' => null];
            }
            $diagnostics[$id] = ['status' => 'descriptive_signals_only', 'health' => 'Insufficient Data', 'health_reason' => 'validated_severity_baseline_and_statistical_model_not_established',
                'baseline' => $windows['baseline'], 'current' => $windows['current'], 'excluded_earliest_days' => array_slice($entityDays, 0, $count - 2 * $length),
                'signals' => $signals, 'stages' => [
                    'delivery' => ['metrics' => ['spend', 'impressions', 'cpm'], 'reach' => null, 'frequency' => null],
                    'traffic' => ['metrics' => ['clicks', 'ctr', 'cpc']],
                    'conversion' => ['metrics' => ['conversions', 'cvr', 'cpa'], 'definition' => $l['scope']['outcome_definition']],
                    'revenue' => ['metrics' => ['conversion_value', 'roas'], 'profit' => null, 'aov' => null]],
                'policy' => 'equal_adjacent_half_windows_descriptive_v1', 'limits' => ['Four days is an engineering comparison minimum, not statistical sufficiency.', 'Direction alone does not establish deterioration, creative fatigue, audience saturation or cause.']];
        }
        $scenarioRows = [];
        foreach ($result['scenarios'] ?? [] as $name => $scenario) {
            if (! is_array($scenario) || ! isset($scenario['check'])) {
                continue;
            }
            $check = $scenario['check'];
            $scenarioRows[] = ['name' => $name === 'current' ? 'no_change_modeled' : $name, 'type' => 'SCENARIO', 'allocation' => $scenario['allocation'],
                'reference_policy' => $scenario['policy'] ?? null, 'reference_limits' => $scenario['limits'] ?? null, 'check_status' => $check['status'], 'spend_exact' => $check['spend_exact'] ?? null, 'response_approximate' => $check['response_approximate'] ?? null,
                'quadratic_risk_evaluation' => $check['quadratic_risk_evaluation'] ?? null, 'objective_approximate' => $check['objective_approximate'] ?? null, 'optimization_measure' => $check['optimization_measure'] ?? null, 'stress' => $check['stress'] ?? null];
        }
        $comparison = (new FrozenScenarioComparison)->build($result);
        $charts = [];
        $series = ['performance_trend' => ['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'],
            'spend_vs_conversions' => ['spend', 'conversions'], 'cpa_trend' => ['cpa'], 'roas_trend' => ['roas']];
        foreach ($series as $name => $metrics) {
            $metricAvailability = array_fill_keys($metrics, $populationComplete && $sourcePopulationQualified);
            foreach ($points as $point) {
                foreach ($metrics as $metric) {
                    if ($this->number($point['ratios'][$metric]) === null) {
                        $metricAvailability[$metric] = false;
                    }
                }
            }
            $available = $name === 'performance_trend' ? in_array(true, $metricAvailability, true) : ! in_array(false, $metricAvailability, true);
            $charts[$name] = ['status' => $available ? 'available_data' : 'unavailable', 'snapshot_id' => $snapshot, 'type' => 'ACTUAL', 'metrics' => $metrics,
                'metric_availability' => $metricAvailability, 'points' => $available ? $points : [], 'reason' => $available ? null : 'incomplete_calendar_population_or_required_operand', 'rendered' => false];
        }
        $charts['budget_allocation'] = ['status' => $scenarioRows ? 'available_data' : 'unavailable', 'snapshot_id' => $snapshot, 'type' => 'SCENARIO', 'scenarios' => $scenarioRows, 'rendered' => false];
        $charts['scenario_comparison'] = ['status' => $scenarioRows ? 'available_data' : 'unavailable', 'snapshot_id' => $snapshot, 'actual' => ['type' => 'ACTUAL', 'totals' => $l['totals']],
            'reference_availability' => array_intersect_key($result['scenarios'] ?? [], ['same_budget_no_change_modeled' => true]), 'modeled_scenarios' => $scenarioRows, 'comparison' => $comparison, 'same_total_spend_claim' => false, 'causal_lift_claim' => false, 'rendered' => false];
        $charts['campaign_health_matrix'] = ['status' => 'descriptive_data_only', 'snapshot_id' => $snapshot, 'entities' => $diagnostics, 'rendered' => false];
        foreach (['marginal_return', 'response_curve', 'saturation_curve', 'audience_matrix', 'creative_matrix', 'platform_comparison', 'sensitivity_chart', 'confidence_interval'] as $name) {
            $charts[$name] = ['status' => 'unavailable', 'snapshot_id' => $snapshot, 'points' => [], 'rendered' => false, 'reason' => match ($name) {
                'marginal_return','response_curve' => 'model_plot_sampling_and_numerical_admission_required',
                'saturation_curve','audience_matrix' => 'comparable_audience_history_and_identified_response_required',
                'creative_matrix' => 'temporal_creative_identity_and_comparable_history_required',
                'platform_comparison' => 'cross_source_estimand_attribution_audience_funnel_and_population_comparability_not_established',
                'sensitivity_chart' => 'coherent_primitive_shock_graph_and_reoptimization_not_computed',
                default => 'applicable_interval_method_and_empirical_calibration_not_available'
            }];
        }
        $plots = (new FrozenModelPlotEvidence)->build($result);
        foreach (['marginal_return', 'response_curve'] as $name) {
            $charts[$name] = $plots + ['type' => 'SCENARIO'];
        }
        if (isset($result['sensitivity'])) {
            $charts['sensitivity_chart'] = ['status' => $result['sensitivity']['status'], 'snapshot_id' => $snapshot, 'type' => 'SCENARIO', 'data' => $result['sensitivity'], 'rendered' => false];
        }
        $intervals = (new FrozenDailyIntervals)->build($l, $points, $populationComplete && $sourcePopulationQualified);
        $charts['confidence_interval'] = $intervals + ['rendered' => false];
        $forecast = $result['forecast'] ?? ['status' => 'unavailable'];
        // Allowlist computed facts only. Raw source bytes, source rows and account credentials stay out. Entity keys remain for traceable internal comparison.
        $analyst = [
            'actual_metrics' => ['type' => 'ACTUAL', 'snapshot_id' => $snapshot, 'currency' => $l['scope']['currency'], 'window' => $l['scope']['reporting_window'],
                'population' => $l['scope']['population'], 'outcome_definition' => $l['scope']['outcome_definition'], 'totals' => $l['totals'], 'ratios' => $l['ratios']],
            'forecast' => ['type' => 'EXPECTED', 'snapshot_id' => $snapshot, 'status' => $forecast['status'], 'points' => $forecast['points'] ?? [], 'calibration' => $forecast['calibration'] ?? 'not_established'],
            'optimization' => ['type' => 'SCENARIO', 'snapshot_id' => $snapshot, 'status' => $result['status'], 'response_unit' => $result['problem']['response_unit'] ?? null, 'currency' => $l['scope']['currency'], 'objective' => $result['problem']['objective'], 'target_optimization' => $result['problem']['target_optimization'] ?? null,
                'quadratic_risk' => $result['problem']['quadratic_risk'] ?? null, 'scenarios' => $scenarioRows, 'comparison' => $comparison, 'primitive_scenarios' => $result['primitive_scenarios'] ?? ['status' => 'not_requested'], 'certificate' => $result['certificate'] ?? null, 'action' => 'SIMULATION_ONLY'],
            'diagnostics' => ['snapshot_id' => $snapshot, 'status' => 'descriptive_only', 'entities' => $diagnostics],
            'confidence' => ['snapshot_id' => $snapshot, 'allocation_probability' => null, 'forecast_probability' => null, 'reason' => 'no_calibrated_probability_or_prediction_interval', 'conditional_daily_mean_intervals' => $intervals, 'stress_is_probability' => false],
            'data_quality' => ['snapshot_id' => $snapshot, 'calendar_population_complete' => $populationComplete, 'source_population_qualified' => $sourcePopulationQualified, 'source_truth' => $l['source_truth'],
                'completeness' => $l['completeness'], 'deduplication' => $l['deduplication'], 'route_reasons' => $result['route']['reasons'] ?? [],
                'provenance_kind' => $l['scope']['provenance_kind'], 'scope_warning' => 'Calendar completeness within supplied rows does not establish source inventory or tracking completeness.']];

        return ['hierarchy' => (new DeclaredHierarchyConstraints)->evidence($result), 'linked_attribution' => (new FrozenLinkedAttribution)->build($l), 'linked_funnel' => (new FrozenLinkedFunnel)->build($l), 'decision_review' => (new FrozenDecisionReview)->build($result), 'anomaly_evidence' => (new FrozenAnomalyEvidence)->build($l), 'calendar_coverage' => (new SuppliedCalendarCoverage)->build($l), 'allocation_incidence' => (new AtomicAllocationIncidence)->build($result), 'explanation' => (new FrozenEvidenceExplanation)->build($snapshot, $analyst), 'contribution_trace' => (new RecordedContributionTrace)->build($l), 'status' => 'computed_read_only', 'snapshot_id' => $snapshot, 'daily' => $points, 'diagnostics' => $diagnostics, 'charts' => $charts, 'analyst_input' => $analyst,
            'external_ai_called' => false, 'operational_approval' => false];
    }

    private function sum(array $values): ?string
    {
        if (in_array(null, $values, true)) {
            return null;
        }
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus($value);
        }

        return (string) $sum;
    }

    private function number(array $value): ?BigRational
    {
        if (($value['decimal'] ?? null) !== null) {
            return BigRational::of($value['decimal']);
        }
        $f = $value['fraction'] ?? null;

        return $f === null ? null : BigRational::of($f['numerator'])->dividedBy($f['denominator']);
    }

    private function fraction(BigRational $value): array
    {
        return ['numerator' => (string) $value->getNumerator(), 'denominator' => (string) $value->getDenominator()];
    }
}
