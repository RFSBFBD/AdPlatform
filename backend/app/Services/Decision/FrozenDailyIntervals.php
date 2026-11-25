<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\Statistics\ConfidenceIntervals;
use App\Services\Decision\Accounting\Binary64Boundary;
use Brick\Math\BigRational;

/** Conditional daily-mean intervals, not forecast intervals or calibrated campaign success. */
final class FrozenDailyIntervals
{
    public function build(array $ledger, array $points, bool $qualified): array
    {
        $out = ['snapshot_id' => $ledger['snapshot_id'], 'status' => 'unavailable', 'type' => 'DESCRIPTIVE_STATISTICAL_ESTIMATE', 'method' => 'daily_mean_student_t_v1', 'level' => 0.95, 'population' => $ledger['scope']['population'], 'window' => $ledger['scope']['reporting_window'], 'rows' => [], 'prediction_interval' => false, 'empirical_calibration' => 'not_established', 'limits' => [
            'Conditional t-intervals for a mean of supplied complete daily observations, assuming independent identically distributed days and an adequate normal approximation. Independence and distributional assumptions are not verified.',
            'Autocorrelation, changing spend, auction drift and source/model bias are not modelled. This is not a future prediction interval, causal effect or calibrated success probability.',
            'Daily ratio means are unweighted averages of defined per-day ratios, not ratios of pooled totals. Intervals are pointwise, with no familywise or simultaneous coverage claim.',
            'Seven days is an engineering minimum, not proof of statistical sufficiency. Negative endpoints are not clipped, even for nonnegative metrics. Zero variance refuses instead of implying certainty.',
            'Wilson intervals are not inferred from advertising event totals: independent Bernoulli trials have not been established.',
        ]];
        if (! $qualified) {
            return $out + ['reason' => 'complete_calendar_and_qualified_supplied_population_required'];
        }
        foreach (['spend', 'clicks', 'conversions', 'conversion_value', 'cpa', 'roas'] as $metric) {
            $exact = [];
            $reason = null;
            foreach ($points as $point) {
                $cell = $point['ratios'][$metric] ?? [];
                $fraction = $cell['fraction'] ?? null;
                $value = ($cell['decimal'] ?? null) !== null ? BigRational::of($cell['decimal']) : ($fraction !== null ? BigRational::of($fraction['numerator'])->dividedBy($fraction['denominator']) : null);
                if ($value === null) {
                    $reason = 'missing_or_undefined_daily_operand_no_day_dropping';
                    break;
                }
                $approx = $value->toFloat();
                if (! is_finite($approx) || abs($approx) > 1e12 || Binary64Boundary::rational($approx)->minus($value)->abs()->isGreaterThan('0.000000001')) {
                    $reason = 'daily_value_outside_declared_binary64_interval_boundary';
                    break;
                }
                $exact[] = ['date' => $point['date'], 'numerator' => (string) $value->getNumerator(), 'denominator' => (string) $value->getDenominator(), 'approximate' => $approx];
            }
            $interval = $reason === null ? ConfidenceIntervals::dailyMean(array_column($exact, 'approximate')) : null;
            $row = ['metric' => $metric, 'estimand' => in_array($metric, ['cpa', 'roas'], true) ? 'unweighted_mean_of_defined_daily_ratios_not_pooled_ratio' : 'mean_daily_recorded_total', 'unit' => match ($metric) {
                'spend', 'conversion_value' => $ledger['scope']['currency'].'/day', 'cpa' => $ledger['scope']['currency'].'/declared_outcome', 'roas' => 'declared_value/spend', default => 'recorded_'.$metric.'/day'
            }, 'status' => $interval === null ? 'unavailable' : 'conditional_interval_computed', 'reason' => $reason ?? ($interval === null ? 'at_least_seven_complete_nonconstant_days_required' : null), 'observations' => $exact, 'interval_approximate' => $interval];
            $out['rows'][] = $row;
        }
        $out['status'] = in_array('conditional_interval_computed', array_column($out['rows'], 'status'), true) ? 'conditional_daily_mean_intervals' : 'unavailable';

        return $out;
    }
}
