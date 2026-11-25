<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;
use Throwable;

/** Recover a nominated active vertex using exact problem constraints. No decimal grid or financial rounding. */
final class ExactLinearVertexRecovery
{
    public function recover(DecisionProblem $problem, array $numericAllocation, array $slopes, bool $completeUsingCandidate = false): array
    {
        $p = $problem->jsonSerialize();
        $variables = $p['variables'];
        $n = count($variables);
        if ($n > 40) {
            return ['status' => 'unavailable', 'reason' => 'exact_vertex_dimension_resource_limit'];
        }
        $rows = [];
        $labels = [];
        $add = function (array $row, string|BigRational $rhs, string $label) use (&$rows, &$labels, $variables, $numericAllocation) {
            $b = $rhs instanceof BigRational ? $rhs : BigRational::of($rhs);
            $approx = 0.;
            $scale = max(1., abs($b->toFloat()));
            foreach ($row as $j => $a) {
                $term = $a->toFloat() * $numericAllocation[$variables[$j]['id']];
                $approx += $term;
                $scale = max($scale, abs($term));
            }
            // Nomination only, not accepted feasibility tolerance. All recovered constraints are checked exactly later.
            if (abs($approx - $b->toFloat()) > 1e-8 * $scale) {
                return;
            }
            $rows[] = array_merge($row, [$b]);
            $labels[] = $label;
        };
        $zero = BigRational::zero();
        $one = BigRational::one();
        foreach ($variables as $j => $v) {
            $row = array_fill(0, $n, $zero);
            $row[$j] = $one;
            $add($row, $v['minimum'], 'minimum:'.$v['id']);
            $add($row, $v['maximum'], 'maximum:'.$v['id']);
        }
        $ones = array_fill(0, $n, $one);
        $add($ones, $p['budget_cap'], 'budget_cap');
        $add($ones, $p['minimum_spend'], 'minimum_spend');
        foreach ($p['groups'] as $g) {
            $row = array_map(fn ($v) => in_array($v['id'], $g['members'], true) ? $one : $zero, $variables);
            $add($row, $g['minimum'], 'group_min:'.$g['id']);
            $add($row, $g['maximum'], 'group_max:'.$g['id']);
        }
        $a = array_map(fn ($s) => Binary64Boundary::rational($s), $slopes);
        $add($a, $p['minimum_response'], 'minimum_response');
        if (str_starts_with($p['objective'], 'TARGET_')) {
            $target = BigRational::of($p['target']);
            $targetRow = match ($p['objective']) {
                'TARGET_REVENUE' => $a,'TARGET_CPA' => array_map(fn ($s) => $one->minus($target->multipliedBy($s)), $a),'TARGET_ROAS' => array_map(fn ($s) => $s->minus($target), $a)
            };
            $add($targetRow, $p['objective'] === 'TARGET_REVENUE' ? $p['target'] : '0', 'target');
        }
        if ($completeUsingCandidate) {
            foreach ($variables as $j => $v) {
                $row = array_fill(0, $n, $zero);
                $row[$j] = $one;
                $rows[] = array_merge($row, [Binary64Boundary::rational((float) $numericAllocation[$v['id']])]);
                $labels[] = 'candidate_coordinate:'.$v['id'];
            }
        }
        $rank = 0;
        $basis = [];
        try {
            for ($column = 0; $column < $n; $column++) {
                $pivot = null;
                for ($i = $rank; $i < count($rows); $i++) {
                    if (! $rows[$i][$column]->isZero()) {
                        $pivot = $i;
                        break;
                    }
                }
                if ($pivot === null) {
                    continue;
                }
                [$rows[$rank],$rows[$pivot]] = [$rows[$pivot], $rows[$rank]];
                [$labels[$rank],$labels[$pivot]] = [$labels[$pivot], $labels[$rank]];
                $d = $rows[$rank][$column];
                foreach ($rows[$rank] as $j => $value) {
                    $rows[$rank][$j] = $value->dividedBy($d);
                }
                foreach ($rows as $i => $row) {
                    if ($i === $rank || $row[$column]->isZero()) {
                        continue;
                    }$factor = $row[$column];
                    foreach ($row as $j => $value) {
                        $rows[$i][$j] = $value->minus($factor->multipliedBy($rows[$rank][$j]));
                        if (strlen((string) $rows[$i][$j]) > 16384) {
                            return ['status' => 'unavailable', 'reason' => 'exact_vertex_digit_resource_limit'];
                        }
                    }
                }
                $basis[$column] = $rank;
                $rank++;
            }
            if ($rank !== $n) {
                return ['status' => 'unavailable', 'reason' => 'nominated_active_constraints_not_full_rank'];
            }
            $allocation = [];
            foreach ($variables as $j => $v) {
                $exact = $rows[$basis[$j]][$n];
                $decimal = (string) $exact->toBigDecimal();
                ExactValue::of($decimal);
                if (abs($exact->toFloat() - $numericAllocation[$v['id']]) > 1e-7 * max(1., abs($numericAllocation[$v['id']]))) {
                    return ['status' => 'unavailable', 'reason' => 'recovered_vertex_not_near_candidate'];
                }
                $allocation[$v['id']] = $decimal;
            }

            return ['status' => 'recovered_candidate', 'allocation' => $allocation, 'active_basis_labels' => array_slice($labels, 0, $n),
                'method' => $completeUsingCandidate ? 'exact_active_equalities_with_free_candidate_coordinates' : 'exact_rational_gaussian_elimination_on_nominated_active_constraints', 'rounding' => 'none', 'feasibility' => 'requires_independent_check',
                'limits' => 'Active set nominated numerically. Recovery is not a proof of optimum, source truth or a settlement quantum. Nonterminating decimal allocations reject.'];
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'reason' => 'nonterminating_or_unsupported_exact_vertex'];
        }
    }
}
