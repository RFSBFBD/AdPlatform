<?php

namespace App\Services\OperationsResearch;

use App\Services\OperationsResearch\Strategies\GenericCurveOptimizer;

/** For concave increasing F(s), F(s)/s is quasiconcave on s>0. Never defaults minimum spend. */
final class PortfolioRatioOptimizer
{
    public function optimize(array $channels, float $minimumSpend, float $maximumSpend): array
    {
        $lo = max($minimumSpend, array_sum(array_column($channels, 'min')));
        $hi = min($maximumSpend, array_sum(array_column($channels, 'max')));
        if (! is_finite($minimumSpend) || ! is_finite($maximumSpend) || $minimumSpend <= 0 || $lo > $hi) {
            return ['status' => 'constraints_required', 'reason' => 'Positive explicit minimum total spend and feasible maximum required.'];
        }
        $cache = [];
        $evaluate = function (float $s) use ($channels, &$cache) {
            $key = sprintf('%.10f', $s);
            if (! isset($cache[$key])) {
                $r = (new GenericCurveOptimizer)->optimize($s, $channels)->toArray();
                if ($r['status'] !== 'ok') {
                    throw new \RuntimeException('An exact-budget inner solve failed its certificate.');
                }$allocation = array_column($r['channel_allocations'], 'solver_spend', 'channel');
                $spent = array_sum($allocation);
                $response = 0.0;
                foreach ($channels as $ch) {
                    $response += $ch['curve']->revenueAt($allocation[$ch['channel']]);
                }$cache[$key] = ['ratio' => $spent > 0 ? $response / $spent : 0, 'spend' => $spent, 'result' => $r];
            }

return $cache[$key];
        };
        $left = $lo;
        $right = $hi;
        $iterations = 0;
        try {
            $best = $evaluate($lo);
            $end = $evaluate($hi);
            if ($end['ratio'] > $best['ratio']) {
                $best = $end;
            }
            $phi = (sqrt(5) - 1) / 2;
            $c = $right - $phi * ($right - $left);
            $d = $left + $phi * ($right - $left);
            $fc = $evaluate($c);
            $fd = $evaluate($d);
            for (; $iterations < 80 && $right - $left > 0.01; $iterations++) {
                foreach ([$fc, $fd] as $v) {
                    if ($v['ratio'] > $best['ratio']) {
                        $best = $v;
                    }
                }
                if ($fc['ratio'] < $fd['ratio']) {
                    $left = $c;
                    $c = $d;
                    $fc = $fd;
                    $d = $left + $phi * ($right - $left);
                    $fd = $evaluate($d);
                } else {
                    $right = $d;
                    $d = $c;
                    $fd = $fc;
                    $c = $right - $phi * ($right - $left);
                    $fc = $evaluate($c);
                }
            }
            foreach ([$evaluate(($left + $right) / 2), $fc, $fd] as $v) {
                if ($v['ratio'] > $best['ratio']) {
                    $best = $v;
                }
            }
        } catch (\RuntimeException $e) {
            return ['status' => 'inner_solver_failed', 'reason' => $e->getMessage()];
        }
        if ($best['ratio'] <= 0) {
            return ['status' => 'no_positive_response', 'reason' => 'No positive modeled response for the permitted spend.'];
        }

        return ['status' => 'ok', 'result' => $best['result'], 'ratio' => $best['ratio'], 'actual_spend' => $best['spend'], 'search' => ['method' => 'golden_section_on_concave_response_frontier_ratio', 'minimum_spend' => $minimumSpend, 'maximum_spend' => $maximumSpend, 'terminal_bracket' => [$left, $right], 'iterations' => $iterations, 'inner_budget_tolerance' => 0.01, 'outer_global_certificate' => false, 'note' => 'Numerical quasiconcave ratio search with certified concave inner allocations. Inner tolerance can perturb the outer optimum; no exact global outer error bound is claimed. Ratio is not a causal or statistical guarantee.']];
    }

    public function target(array $channels, float $minimumSpend, float $maximumSpend, float $minimumResponsePerSpend): array
    {
        if (! is_finite($minimumResponsePerSpend) || $minimumResponsePerSpend <= 0) {
            return ['status' => 'constraints_required', 'reason' => 'Explicit positive finite target response per spend required.'];
        }
        $peak = $this->optimize($channels, $minimumSpend, $maximumSpend);
        if ($peak['status'] !== 'ok') {
            return $peak;
        }
        if ($peak['ratio'] < $minimumResponsePerSpend) {
            return ['status' => 'infeasible', 'reason' => 'The stated target ratio exceeds the numerical maximum within the bounds. No allocation.'];
        }
        $at = function (float $spend) use ($channels) {
            $r = (new GenericCurveOptimizer)->optimize($spend, $channels)->toArray();
            if ($r['status'] !== 'ok') {
                return null;
            }$a = array_column($r['channel_allocations'], 'solver_spend', 'channel');
            $s = array_sum($a);
            $v = 0;
            foreach ($channels as $ch) {
                $v += $ch['curve']->revenueAt($a[$ch['channel']]);
            }

return ['result' => $r, 'actual_spend' => $s, 'ratio' => $s > 0 ? $v / $s : 0];
        };
        $lo = $peak['actual_spend'];
        $hi = min($maximumSpend, array_sum(array_column($channels, 'max')));
        $top = $at($hi);
        if ($top === null) {
            return ['status' => 'inner_solver_failed', 'reason' => 'Target upper-cap solve failed.'];
        }
        if ($top['ratio'] >= $minimumResponsePerSpend) {
            $best = $top;
        } else {
            $best = $peak;
            for ($i = 0; $i < 80 && $hi - $lo > 0.01; $i++) {
                $mid = ($lo + $hi) / 2;
                $r = $at($mid);
                if ($r === null) {
                    return ['status' => 'inner_solver_failed', 'reason' => 'Target search inner solve failed.'];
                }if ($r['ratio'] >= $minimumResponsePerSpend) {
                    $lo = $mid;
                    $best = $r;
                } else {
                    $hi = $mid;
                }
            }
        }

        return ['status' => 'ok', 'result' => $best['result'], 'actual_spend' => $best['actual_spend'], 'ratio' => $best['ratio'], 'search' => ['method' => 'rightmost_feasible_spend_on_quasiconcave_ratio_frontier', 'target_minimum_response_per_spend' => $minimumResponsePerSpend, 'peak_search' => $peak['search'], 'boundary_bracket' => [$lo, $hi], 'outer_global_certificate' => false, 'note' => 'Maximizes modeled response on increasing concave curves subject to ratio target, spend cap and explicit minimum. Numerical search, no exact global outer certificate and no guaranteed realized CPA/ROAS.']];
    }
}
