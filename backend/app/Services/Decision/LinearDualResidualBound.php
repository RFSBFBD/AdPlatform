<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;

/** Box-bounded residual correction to a represented linear objective upper bound. No floating stationarity assumption. */
final class LinearDualResidualBound
{
    public function maximum(DecisionProblem $problem, array $slopes, array $step, array $extraRows = [], array $extraRhs = []): array
    {
        $p = $problem->jsonSerialize();
        $n = count($p['variables']);
        if ($p['allocation_mode'] !== 'continuous_linear' || ! in_array($p['objective'], ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS'], true) || ! ExactValue::of($p['minimum_response'])->decimal()->isZero() || isset($p['risk']) || count($slopes) !== $n || ! is_array($step['dual_inequality'] ?? null) || count($step['dual_inequality']) !== 3 + 2 * count($p['groups']) + count($extraRows) || ! is_array($step['dual_lower'] ?? null) || ! is_array($step['dual_upper'] ?? null) || count($step['dual_lower']) !== $n || count($step['dual_upper']) !== $n) {
            return ['status' => 'unavailable', 'reason' => 'unsupported_linear_bound_scope'];
        }
        foreach (array_merge($slopes, $step['dual_inequality'], $step['dual_lower'], $step['dual_upper']) as $number) {
            if (! ($number instanceof BigRational) && (! is_numeric($number) || ! is_finite((float) $number))) {
                return ['status' => 'unavailable', 'reason' => 'nonfinite_linear_bound_operand'];
            }
        }
        // Project dual signs exactly. Any stationarity residue is paid for over the complete original variable box.
        $one = BigRational::one();
        $zero = BigRational::zero();
        $matrix = [array_fill(0, $n, $one), array_fill(0, $n, $one->negated()), array_fill(0, $n, $zero)];
        $rhs = [BigRational::of($p['budget_cap']), BigRational::of($p['minimum_spend'])->negated(), $zero];
        foreach ($p['groups'] as $g) {
            $row = array_map(fn ($v) => in_array($v['id'], $g['members'], true) ? $one : $zero, $p['variables']);
            $matrix[] = $row;
            $rhs[] = BigRational::of($g['maximum']);
            $matrix[] = array_map(fn ($x) => $x->negated(), $row);
            $rhs[] = BigRational::of($g['minimum'])->negated();
        }
        if (count($extraRows) !== count($extraRhs)) {
            return ['status' => 'unavailable', 'reason' => 'extra_bound_row_dimensions'];
        }
        foreach ($extraRows as $i => $row) {
            if (count($row) !== $n) {
                return ['status' => 'unavailable', 'reason' => 'extra_bound_row_dimensions'];
            }
            $matrix[] = array_map(fn ($x) => BigRational::of($x), $row);
            $rhs[] = BigRational::of($extraRhs[$i]);
        }
        $y = array_map(fn ($d) => Binary64Boundary::rational(min(0., (float) $d)), $step['dual_inequality']);
        $lo = array_map(fn ($d) => Binary64Boundary::rational(max(0., (float) $d)), $step['dual_lower']);
        $hi = array_map(fn ($d) => Binary64Boundary::rational(min(0., (float) $d)), $step['dual_upper']);
        $lower = $zero;
        foreach ($y as $i => $d) {
            if ($d->isGreaterThan(0)) {
                return ['status' => 'unavailable', 'reason' => 'dual_sign'];
            }$lower = $lower->plus($rhs[$i]->multipliedBy($d));
        }
        $correction = $zero;
        foreach ($p['variables'] as $j => $v) {
            if ($lo[$j]->isLessThan(0) || $hi[$j]->isGreaterThan(0)) {
                return ['status' => 'unavailable', 'reason' => 'bound_dual_sign'];
            }
            $lower = $lower->plus($lo[$j]->multipliedBy($v['minimum']))->plus($hi[$j]->multipliedBy($v['maximum']));
            $residual = ($slopes[$j] instanceof BigRational ? $slopes[$j] : Binary64Boundary::rational((float) $slopes[$j]))->negated()->minus($lo[$j])->minus($hi[$j]);
            foreach ($matrix as $i => $row) {
                $residual = $residual->minus($row[$j]->multipliedBy($y[$i]));
            }
            $correction = $correction->plus($residual->multipliedBy($residual->isLessThan(0) ? $v['maximum'] : $v['minimum']));
        }
        $upper = $lower->plus($correction)->negated();

        return ['status' => 'bounded', 'upper_exact' => ExactValue::fraction($upper), 'upper_approximate' => $upper->toFloat(), 'dual_residual_correction_exact' => ExactValue::fraction($correction),
            'scope' => 'exact_rational_upper_bound_for_represented_linear_coefficients_over_original_decimal_box_budget_groups_and_declared_extra_rows'];
    }
}
