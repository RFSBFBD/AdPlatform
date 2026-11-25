<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\ResponseCurveFitter;
use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\RecordedLedger;

/** Uses the SAME exact ledger for fit operands, history and observed current spend. */
final class LedgerModelBuilder
{
    public function build(RecordedLedger $ledger, DecisionProblem $problem): array
    {
        $l = $ledger->jsonSerialize();
        $p = $problem->jsonSerialize();
        $models = [];
        $failures = [];
        foreach ($p['variables'] as $v) {
            $history = [];
            foreach ($l['rows'] as $key => $r) {
                if ($r['variable_id'] !== $v['id']) {
                    continue;
                }
                // The row key is not a calendar date. A source-verified explicit day is mandatory.
                $date = $r['reporting_date'] ?? null;
                if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                    || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date
                    || isset($history[$date])) {
                    $failures[$v['id']] = 'distinct_explicit_calendar_day_required';
                    break;
                }
                if (($r['metrics'][$p['response_metric']]['decimal'] ?? null) === null) {
                    $failures[$v['id']] = 'required_response_missing_not_zero';
                    break;
                }
                $x = $r['metrics']['spend']['decimal'];
                $y = $r['metrics'][$p['response_metric']]['decimal'];
                foreach ([$x, $y] as $number) {
                    $numeric = (float) $number;
                    if (! is_finite($numeric) || $numeric > 1e12 || Binary64Boundary::loss($number, $numeric)->isGreaterThan('0.0001')) {
                        $failures[$v['id']] = 'fit_operand_numeric_range_or_precision_loss';
                        break 2;
                    }
                }
                $history[$date] = ['spend' => (float) $x, 'response' => (float) $y, 'source_row' => $key];
            }
            if (isset($failures[$v['id']])) {
                continue;
            }
            ksort($history, SORT_STRING);
            $dates = array_keys($history);
            if (count($dates) !== $p['horizon_days'] || $dates === [] || $p['reporting_window'] !== $dates[0].'/'.$dates[count($dates) - 1]) {
                $failures[$v['id']] = 'reference_route_requires_matching_explicit_complete_window_and_horizon';

                continue;
            }
            for ($i = 1; $i < count($dates); $i++) {
                if ((new \DateTimeImmutable($dates[$i - 1]))->modify('+1 day')->format('Y-m-d') !== $dates[$i]) {
                    $failures[$v['id']] = 'calendar_gaps_not_zero_filled';
                    break;
                }
            }
            if (isset($failures[$v['id']])) {
                continue;
            }
            $fit = (new ResponseCurveFitter)->fit(array_values($history), true);
            if ($fit['status'] !== 'ok') {
                $failures[$v['id']] = $fit;

                continue;
            }
            $values = array_column($history, 'response');
            $mean = array_sum($values) / count($values);
            $variance = 0.0;
            foreach ($values as $value) {
                $variance += ($value - $mean) ** 2;
            }
            $variance /= max(1, count($values) - 1);
            $models[$v['id']] = ['curve' => $fit['curve']->overDays($p['horizon_days']),
                'model_version' => 'ledger-fitter-v1', 'source_snapshot_id' => $l['snapshot_id'],
                'objective' => $p['objective'], 'platform' => $v['platform'], 'campaign_type' => $v['campaign_type'],
                'period_days' => $p['horizon_days'], 'data_volume' => count($history), 'history_days' => count($history),
                'variance' => $variance, 'response_shape' => $fit['model'], 'source_admission' => $l['scope']['provenance_kind'],
                'data_quality' => ['hard_gates' => 'passed', 'policy_version' => 'calendar-lexical-only-v1',
                    'limits' => 'Local dates/precision checks only. Does not establish provider coverage, tracking or finality.'],
                'validation' => ['status' => 'passed', 'method' => $fit['validation']['method'],
                    'evidence_id' => hash('sha256', RecordedLedger::canonical([$l['snapshot_id'], $v['id'], $fit['validation']])),
                    'details' => $fit['validation'], 'candidates' => $fit['candidates']],
                'period_assumption' => 'equal_daily_spend', 'causal_claim' => false, 'empirically_calibrated' => false];
        }

        return ['status' => $failures ? 'not_all_models_admitted' : 'built', 'models' => $models, 'failures' => $failures];
    }
}
