<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\RollingForecastEvidence;
use PHPUnit\Framework\TestCase;

final class RollingForecastEvidenceTest extends TestCase
{
    private function ledger(array $values): RecordedLedger
    {
        $end = (new \DateTimeImmutable('2026-10-01'))->modify('+'.(count($values) - 1).' days')->format('Y-m-d');
        $scope = ['provider' => 'synthetic', 'account_id' => 'synthetic', 'source_contract' => 'synthetic', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'reporting_window' => '2026-10-01/'.$end, 'outcome_definition' => 'synthetic_credit', 'outcome_kind' => 'other_explicit', 'attribution' => 'synthetic',
            'population' => 'a', 'conversion_count_kind' => 'integral', 'provenance_kind' => 'synthetic_fixture'];
        $rows = [];
        foreach ($values as $i => $value) {
            $date = (new \DateTimeImmutable('2026-10-01'))->modify('+'.$i.' days')->format('Y-m-d');
            $rows[] = ['key' => 'a:'.$date, 'variable_id' => 'a', 'source_ref' => 'synthetic:'.$date, 'scope' => $scope, 'reporting_date' => $date, 'spend' => '1', 'conversions' => (string) $value];
        }

        return new RecordedLedger($scope, $rows);
    }

    public function test_each_lead_error_is_independently_recomputed_and_original_operands_remain_exact(): void
    {
        $r = (new RollingForecastEvidence)->evaluate($this->ledger(range(1, 30)), 'conversions', 3);
        self::assertSame(14, $r['evaluated_origins']);
        self::assertSame('linear_trend_nonnegative', $r['selected']);
        foreach ($r['evaluation_by_lead'] as $candidate => $leads) {
            foreach ($leads as $lead) {
                $errors = [];
                foreach ($r['origins'] as $origin) {
                    $i = $lead['lead_days'] - 1;
                    $errors[] = $origin['predictions'][$candidate][$i] - (float) $origin['actual_recorded_exact'][$i];
                }
                self::assertSame(count($errors), $lead['evaluated_origins']);
                self::assertEqualsWithDelta(sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors)), $lead['rmse_approximate'], 1e-12);
                self::assertEqualsWithDelta(array_sum(array_map('abs', $errors)) / count($errors), $lead['mae_approximate'], 1e-12);
                self::assertEqualsWithDelta(array_sum($errors) / count($errors), $lead['bias_prediction_minus_actual_approximate'], 1e-12);
            }
        }
        self::assertSame('15', $r['origins'][0]['actual_recorded_exact'][0]);
        self::assertNull($r['probability_of_success']);
        self::assertNull($r['points'][0]['lower']);
    }

    public function test_future_changes_cannot_change_predictions_at_an_earlier_origin(): void
    {
        $engine = new RollingForecastEvidence;
        $values = range(1, 30);
        $before = $engine->evaluate($this->ledger($values), 'conversions', 3);
        $values[29] = 99999;
        $after = $engine->evaluate($this->ledger($values), 'conversions', 3);
        foreach ($before['origins'] as $i => $origin) {
            self::assertSame($origin['predictions'], $after['origins'][$i]['predictions']);
        }
        self::assertNotSame($before['origins'][13]['actual_recorded_exact'], $after['origins'][13]['actual_recorded_exact']);
        self::assertSame('not_established', $after['calibration']);
    }

    public function test_weekly_candidate_repeats_training_week_beyond_seven_days_and_wins_only_from_rolling_error(): void
    {
        $week = [1, 3, 2, 7, 5, 20, 30];
        $values = [];
        for ($i = 0; $i < 42; $i++) {
            $values[] = $week[$i % 7];
        }
        $r = (new RollingForecastEvidence)->evaluate($this->ledger($values), 'conversions', 10);
        self::assertSame('weekly_seasonal_naive', $r['selected']);
        self::assertSame(0., $r['rmse_approximate']['weekly_seasonal_naive']);
        self::assertSame([1., 3., 2., 7., 5., 20., 30., 1., 3., 2.], array_column($r['points'], 'expected_approximate'));
        foreach ($r['origins'] as $origin) {
            self::assertSame($origin['predictions']['weekly_seasonal_naive'][0], $origin['predictions']['weekly_seasonal_naive'][7]);
        }
        $values[41] = 9999;
        $changed = (new RollingForecastEvidence)->evaluate($this->ledger($values), 'conversions', 10);
        self::assertSame(array_column($r['origins'], 'predictions'), array_column($changed['origins'], 'predictions'));
        self::assertNull($changed['points'][0]['lower']);
    }

    public function test_adaptive_candidate_selection_uses_only_fully_matured_prior_horizons(): void
    {
        $engine = new RollingForecastEvidence;
        $values = range(1, 42);
        $before = $engine->evaluate($this->ledger($values), 'conversions', 3)['prequential_selection_evaluation'];
        self::assertSame('evaluated_adaptive_selection_on_later_origins', $before['status']);
        foreach ($before['origins'] as $origin) {
            self::assertLessThanOrEqual($origin['train_through'], $origin['selection_outcomes_through']);
            self::assertGreaterThanOrEqual(4, $origin['completed_prior_origins']);
            self::assertSame('linear_trend_nonnegative', $origin['selected']);
        }
        foreach ($before['evaluation_by_lead'] as $lead) {
            self::assertSame(0., $lead['rmse_approximate']);
        }
        $values[41] = 99999;
        $after = $engine->evaluate($this->ledger($values), 'conversions', 3)['prequential_selection_evaluation'];
        foreach ($before['origins'] as $i => $origin) {
            self::assertSame($origin['selected'], $after['origins'][$i]['selected']);
            self::assertSame($origin['prior_rmse_approximate'], $after['origins'][$i]['prior_rmse_approximate']);
            self::assertSame($origin['predictions_approximate'], $after['origins'][$i]['predictions_approximate']);
        }
        $short = $engine->evaluate($this->ledger(range(1, 20)), 'conversions', 3);
        self::assertSame('insufficient_completed_prior_origins', $short['prequential_selection_evaluation']['status']);
    }

    public function test_constant_zero_ties_keep_naive_baseline_without_invented_interval(): void
    {
        $r = (new RollingForecastEvidence)->evaluate($this->ledger(array_fill(0, 24, 0)), 'conversions', 2);
        self::assertSame('naive_last_value', $r['selected']);
        self::assertSame(0.0, $r['evaluation_by_lead']['naive_last_value'][0]['rmse_approximate']);
        self::assertSame(0.0, $r['points'][0]['expected_approximate']);
        self::assertSame('unavailable', $r['points'][0]['interval_kind']);
    }
}
