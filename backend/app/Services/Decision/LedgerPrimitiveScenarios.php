<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigRational;

/** Descriptive rate identities from one complete supplied ledger, not an identified auction/causal model. */
final class LedgerPrimitiveScenarios
{
    public function evaluate(RecordedLedger $ledger, array $shocks): array
    {
        $l = $ledger->jsonSerialize();
        $totals = $l['totals'];
        if ($shocks === [] || ! array_is_list($shocks) || count($shocks) > 5) {
            return ['status' => 'unavailable', 'reason' => 'one_to_five_explicit_primitive_shocks_required'];
        }
        foreach (RecordedLedger::METRICS as $key) {
            if ($totals[$key] === null) {
                return ['status' => 'unavailable', 'reason' => 'required_primitive_source_operand_missing:'.$key];
            }
        }
        $entityDays = [];
        $allDays = [];
        foreach ($l['rows'] as $row) {
            $date = $row['reporting_date'];
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date || isset($entityDays[$row['variable_id']][$date])) {
                return ['status' => 'unavailable', 'reason' => 'distinct_valid_entity_dates_required'];
            }
            $entityDays[$row['variable_id']][$date] = true;
            $allDays[$date] = true;
        }
        ksort($allDays, SORT_STRING);
        $dates = array_keys($allDays);
        if ($dates === [] || $l['scope']['reporting_window'] !== $dates[0].'/'.$dates[count($dates) - 1]) {
            return ['status' => 'unavailable', 'reason' => 'explicit_complete_window_required'];
        }
        foreach ($entityDays as $days) {
            if (count($days) !== count($dates)) {
                return ['status' => 'unavailable', 'reason' => 'incomplete_entity_day_population'];
            }
        }
        for ($i = 1; $i < count($dates); $i++) {
            if ((new \DateTimeImmutable($dates[$i - 1]))->modify('+1 day')->format('Y-m-d') !== $dates[$i]) {
                return ['status' => 'unavailable', 'reason' => 'calendar_gaps'];
            }
        }
        foreach (['spend', 'impressions', 'clicks', 'conversions'] as $key) {
            if (BigRational::of($totals[$key])->isZero()) {
                return ['status' => 'unavailable', 'reason' => 'positive_rate_denominator_required:'.$key];
            }
        }
        $x = array_map(fn ($v) => BigRational::of($v), $totals);
        $primitives = ['budget' => ExactValue::fraction($x['spend']), 'cpm' => ExactValue::fraction($x['spend']->multipliedBy(1000)->dividedBy($x['impressions'])),
            'ctr' => ExactValue::fraction($x['clicks']->dividedBy($x['impressions'])), 'cvr' => ExactValue::fraction($x['conversions']->dividedBy($x['clicks'])),
            'value_per_conversion' => ExactValue::fraction($x['conversion_value']->dividedBy($x['conversions']))];
        $cases = [];
        foreach ($shocks as $index => $multipliers) {
            try {
                $r = (new PrimitiveScenarioGraph)->evaluate(['snapshot_id' => $l['snapshot_id'], 'currency' => $l['scope']['currency'], 'outcome_definition' => $l['scope']['outcome_definition'],
                    'basis' => 'budget_cpm_ctr_cvr_value_per_conversion', 'assumption' => 'fixed_rates_same_population_expected_fractional_credits', 'primitives' => $primitives, 'shocks' => $multipliers]);
            } catch (\InvalidArgumentException $e) {
                $r = ['status' => 'unavailable', 'reason' => $e->getMessage()];
            }
            $cases[] = ['index' => $index, 'result' => $r];
        }

        return ['status' => count(array_filter($cases, fn ($case) => $case['result']['status'] !== 'evaluated_assumption_graph')) ? 'not_all_shocks_accepted' : 'evaluated_ledger_primitive_identities',
            'snapshot_id' => $l['snapshot_id'], 'scenarios' => $cases, 'source_population' => $l['scope']['population'], 'rate_estimator' => 'exact_ratio_of_same_window_sums',
            'reoptimized' => false, 'modeled_response_curve_modified' => false, 'limits' => ['Observed aggregate ratios are descriptive, not budget-dependent causal rates.', 'Complete supplied calendar is not verified tracking completeness or provider inventory.', 'No allocation, saturation, audience or unique-customer claim follows.']];
    }
}
