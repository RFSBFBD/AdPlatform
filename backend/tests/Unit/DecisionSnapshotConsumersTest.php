<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionSnapshotConsumers;
use App\Services\Decision\FrozenEvidenceExplanation;
use App\Services\Decision\FrozenModelPlotEvidence;
use App\Services\Decision\RecordedContributionTrace;
use App\Services\Decision\RollingForecastEvidence;
use App\Services\Decision\SuppliedCalendarCoverage;
use PHPUnit\Framework\TestCase;

final class DecisionSnapshotConsumersTest extends TestCase
{
    private function fixtureResult(bool $gap = false, bool $outcome = true): array
    {
        $scope = ['provider' => 'synthetic', 'account_id' => 'private-account-key', 'source_contract' => 'synthetic-1', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'reporting_window' => '2026-11-01/2026-11-05', 'outcome_definition' => 'synthetic-credit', 'outcome_kind' => 'other_explicit', 'attribution' => 'synthetic',
            'population' => 'a', 'conversion_count_kind' => 'integral', 'provenance_kind' => 'synthetic_fixture'];
        $rows = [];
        foreach (range(1, 5) as $day) {
            if ($gap && $day === 3) {
                continue;
            }$date = '2026-11-0'.$day;
            $rows[] = ['key' => 'a:'.$date, 'variable_id' => 'a', 'reporting_date' => $date, 'source_ref' => 'private-source:'.$date, 'scope' => $scope,
                'spend' => (string) $day, 'impressions' => '100', 'clicks' => $day < 4 ? '0' : '2', 'conversions' => $outcome ? '1' : null, 'conversion_value' => null];
        }
        $ledger = new RecordedLedger($scope, $rows);

        return ['actual' => $ledger->jsonSerialize(), 'snapshot_id' => $ledger->jsonSerialize()['snapshot_id'], 'route' => ['status' => 'unsupported_or_insufficient_evidence', 'reasons' => ['fixture']],
            'status' => 'abstained', 'problem' => ['objective' => 'MAXIMIZE_CONVERSIONS', 'response_unit' => 'synthetic-credit'], 'scenarios' => [], 'forecast' => ['status' => 'unavailable']];
    }

    public function test_same_snapshot_exact_daily_ratios_missing_metrics_zero_baseline_and_fifteen_typed_charts(): void
    {
        $r = $this->fixtureResult();
        $c = (new DecisionSnapshotConsumers)->build($r);
        self::assertCount(15, $c['charts']);
        self::assertSame('available_data', $c['charts']['performance_trend']['status']);
        self::assertTrue($c['charts']['performance_trend']['metric_availability']['spend']);
        self::assertFalse($c['charts']['performance_trend']['metric_availability']['conversion_value']);
        self::assertSame('unavailable', $c['charts']['roas_trend']['status']);
        self::assertSame('descriptive_signals_only', $c['diagnostics']['a']['status']);
        self::assertSame(['2026-11-01'], $c['diagnostics']['a']['excluded_earliest_days']);
        self::assertSame('up', $c['diagnostics']['a']['signals']['ctr']['direction']);
        self::assertTrue($c['diagnostics']['a']['signals']['ctr']['zero_baseline']);
        self::assertNull($c['diagnostics']['a']['signals']['ctr']['change_fraction']);
        self::assertSame('4', $c['diagnostics']['a']['current']['totals']['clicks']);
        self::assertSame('0', $c['diagnostics']['a']['baseline']['totals']['clicks']);
        self::assertSame('Insufficient Data', $c['diagnostics']['a']['health']);
        self::assertNull($c['diagnostics']['a']['stages']['delivery']['frequency']);
        self::assertFalse($c['external_ai_called']);
        self::assertSame(['actual_metrics', 'forecast', 'optimization', 'diagnostics', 'confidence', 'data_quality'], array_keys($c['analyst_input']));
        foreach ($c['analyst_input'] as $block) {
            self::assertSame($r['snapshot_id'], $block['snapshot_id']);
        }
        $json = json_encode($c['analyst_input']);
        self::assertStringNotContainsString('private-account-key', $json);
        self::assertStringNotContainsString('private-source:', $json);
        self::assertNull($c['analyst_input']['confidence']['allocation_probability']);
    }

    public function test_coverage_bounds_missing_date_examples_and_rejects_out_of_window_rows(): void
    {
        $r = $this->fixtureResult();
        $r['actual']['scope']['reporting_window'] = '2026-10-01/2026-11-05';
        $coverage = (new SuppliedCalendarCoverage)->build($r['actual']);
        self::assertSame(31, $coverage['missing_cells_within_supplied_entities']);
        self::assertCount(20, $coverage['entities']['a']['missing_day_examples']);
        self::assertTrue($coverage['entities']['a']['missing_day_examples_truncated']);
        $r['actual']['scope']['reporting_window'] = '2026-11-02/2026-11-05';
        self::assertSame('unavailable', (new SuppliedCalendarCoverage)->build($r['actual'])['status']);
    }

    public function test_forecast_cannot_silently_replace_declared_window_with_returned_row_dates(): void
    {
        $r = $this->fixtureResult();
        $scope = $r['actual']['scope'];
        $scope['reporting_window'] = '2026-10-01/2026-11-05';
        $rows = [];
        foreach ($r['actual']['rows'] as $key => $row) {
            $copy = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $scope];
            foreach ($row['metrics'] as $metric => $value) {
                $copy[$metric] = $value['lexeme'];
            }
            $rows[] = $copy;
        }
        $forecast = (new RollingForecastEvidence)->evaluate(new RecordedLedger($scope, $rows), 'conversions', 1);
        self::assertSame('unavailable', $forecast['status']);
        self::assertSame('forecast_calendar_must_match_declared_source_window', $forecast['reason']);
        self::assertSame([], $forecast['points']);
    }

    public function test_reference_explanation_resolves_every_fact_and_rejects_numeric_or_type_changes(): void
    {
        $c = (new DecisionSnapshotConsumers)->build($this->fixtureResult());
        $engine = new FrozenEvidenceExplanation;
        self::assertTrue($engine->verify($c['explanation'], $c['analyst_input']));
        foreach ($c['explanation']['facts'] as $fact) {
            $value = $c['analyst_input'];
            foreach (explode('/', substr($fact['pointer'], 1)) as $segment) {
                $value = $value[str_replace(['~1', '~0'], ['/', '~'], $segment)];
            }
            self::assertSame($value, $fact['value']);
        }
        $changed = $c['explanation'];
        $changed['facts'][0]['value'] = '15.000000000000000001';
        self::assertFalse($engine->verify($changed, $c['analyst_input']));
        $changed = $c['explanation'];
        $changed['facts'][0]['type'] = 'EXPECTED';
        self::assertFalse($engine->verify($changed, $c['analyst_input']));
        $changed = $c['explanation'];
        array_pop($changed['facts']);
        self::assertFalse($engine->verify($changed, $c['analyst_input']));
        $c['analyst_input']['forecast']['snapshot_id'] = 'other';
        $this->expectException(\InvalidArgumentException::class);
        $engine->build($c['snapshot_id'], $c['analyst_input']);
    }

    public function test_explanation_escapes_entity_pointers_and_withholds_rejected_model_response(): void
    {
        $c = (new DecisionSnapshotConsumers)->build($this->fixtureResult());
        $blocks = $c['analyst_input'];
        $blocks['diagnostics']['entities'] = ['entity/a~b' => ['status' => 'insufficient_data', 'health' => 'Insufficient Data']];
        $blocks['optimization']['scenarios'] = [['name' => 'custom', 'check_status' => 'rejected', 'spend_exact' => '1', 'response_approximate' => 999999]];
        $e = (new FrozenEvidenceExplanation)->build($c['snapshot_id'], $blocks);
        $facts = array_column($e['facts'], null, 'pointer');
        self::assertArrayHasKey('/diagnostics/entities/entity~1a~0b/status', $facts);
        self::assertArrayNotHasKey('/optimization/scenarios/0/response_approximate', $facts);
        self::assertSame('rejected', $facts['/optimization/scenarios/0/check_status']['value']);
    }

    public function test_incomplete_calendar_and_missing_outcomes_never_create_zero_fill_or_successful_chart(): void
    {
        $c = (new DecisionSnapshotConsumers)->build($this->fixtureResult(true, false));
        self::assertFalse($c['analyst_input']['data_quality']['calendar_population_complete']);
        self::assertSame('unavailable', $c['charts']['performance_trend']['status']);
        self::assertSame('insufficient_data', $c['diagnostics']['a']['status']);
        self::assertNull($c['analyst_input']['actual_metrics']['totals']['conversions']);
        self::assertCount(4, $c['daily']);
        self::assertSame(1, $c['calendar_coverage']['missing_cells_within_supplied_entities']);
        self::assertSame(['2026-11-03'], $c['calendar_coverage']['entities']['a']['missing_day_examples']);
        self::assertSame(4, $c['calendar_coverage']['entities']['a']['missing_operands_within_returned_rows']['conversions']);
        self::assertNull($c['calendar_coverage']['source_inventory_complete']);
    }

    public function test_contribution_trace_recomputes_exact_large_operands_and_rejects_mismatch(): void
    {
        $r = $this->fixtureResult();
        $trace = (new RecordedContributionTrace)->build($r['actual']);
        self::assertSame('traced_supplied_contributions', $trace['status']);
        self::assertSame('15', $trace['recomputed_totals']['spend']);
        self::assertNull($trace['recomputed_totals']['conversion_value']);
        self::assertTrue($trace['matches_recorded_totals']['conversion_value']);
        $r['actual']['totals']['spend'] = '14.99999999999999999999999';
        $trace = (new RecordedContributionTrace)->build($r['actual']);
        self::assertSame('rejected_conservation_mismatch', $trace['status']);
    }

    public function test_full_calendar_does_not_qualify_real_source_inventory(): void
    {
        $r = $this->fixtureResult();
        $r['actual']['scope']['provenance_kind'] = 'recorded_source';
        $c = (new DecisionSnapshotConsumers)->build($r);
        self::assertTrue($c['analyst_input']['data_quality']['calendar_population_complete']);
        self::assertTrue($c['calendar_coverage']['supplied_grid_complete']);
        self::assertNull($c['calendar_coverage']['source_inventory_complete']);
        self::assertFalse($c['analyst_input']['data_quality']['source_population_qualified']);
        self::assertSame('unavailable', $c['charts']['performance_trend']['status']);
        $facts = array_column($c['explanation']['facts'], null, 'pointer');
        self::assertFalse($facts['/data_quality/source_population_qualified']['value']);
        self::assertSame('recorded_source', $facts['/data_quality/provenance_kind']['value']);
        self::assertNull($facts['/confidence/allocation_probability']['value']);
        self::assertSame('source_population_not_qualified', $c['diagnostics']['a']['reason']);
        self::assertCount(5, $c['daily']);
    }

    public function test_frozen_curve_menu_plot_derivative_implementation_and_kink_convention(): void
    {
        $r = ['route' => ['status' => 'admitted'], 'snapshot_id' => 'synthetic', 'problem' => ['horizon_days' => 1, 'currency' => 'EGP', 'response_unit' => 'credit',
            'variables' => [['id' => 'a', 'menu' => ['1', '2', '3']]]],
            'model_snapshot' => ['a' => ['curve' => ['name' => 'piecewise', 'parameters' => ['a' => 4., 'b' => .5, 'knot' => 2.], 'support_min' => 1., 'support_max' => 3.]]]];
        $plots = (new FrozenModelPlotEvidence)->build($r);
        self::assertSame('available_data', $plots['status']);
        self::assertSame(8., $plots['curves']['a']['points'][1]['response_approximate']);
        self::assertSame(2., $plots['curves']['a']['points'][1]['marginal_approximate']);
        self::assertSame('not_checked_across_piecewise_kink', $plots['curves']['a']['points'][1]['marginal_check']['method']);
        self::assertLessThan(1e-8, $plots['curves']['a']['points'][0]['marginal_check']['absolute_difference_approximate']);
        $continuous = $r;
        $continuous['problem']['allocation_mode'] = 'continuous_concave';
        $continuous['problem']['variables'][0]['minimum'] = '1.00000000001';
        $continuous['problem']['variables'][0]['maximum'] = '3.00000000001';
        $continuous['model_snapshot']['a']['curve']['support_max'] = 4.;
        $grid = (new FrozenModelPlotEvidence)->build($continuous);
        self::assertCount(21, $grid['curves']['a']['points']);
        self::assertSame('1.1000000000100', $grid['curves']['a']['points'][1]['spend_exact']);
        self::assertFalse($grid['curves']['a']['interpolation_claim']);
        $r['problem']['variables'][0]['menu'] = ['4'];
        self::assertSame('unavailable', (new FrozenModelPlotEvidence)->build($r)['status']);
    }
}
