<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Exact PSD check and evaluation of a declared response-slope covariance penalty. Not an estimated covariance or calibrated risk. */
final class QuadraticRiskPolicy
{
    public function validate(array $p): void
    {
        $r = $p['quadratic_risk'];
        $ids = array_column($p['variables'], 'id');
        $n = count($ids);
        if (! is_array($r) || array_diff(array_keys($r), ['mode', 'lambda', 'lambda_unit', 'covariance_unit', 'evidence_id', 'variable_order', 'covariance']) || ($r['mode'] ?? null) !== 'response_slope_covariance_penalty' || ($r['variable_order'] ?? null) !== $ids || $n > 40 || ! is_string($r['evidence_id'] ?? null) || $r['evidence_id'] === '' || ($r['lambda_unit'] ?? null) !== 'inverse_response_unit' || ($r['covariance_unit'] ?? null) !== 'response_unit_squared_per_currency_squared' || ! is_array($r['covariance'] ?? null) || ! array_is_list($r['covariance']) || count($r['covariance']) !== $n) {
            throw new InvalidArgumentException('explicit_quadratic_risk_contract_required');
        }
        if (strlen((string) ($r['lambda'] ?? '')) > 128) {
            throw new InvalidArgumentException('quadratic_policy_precision_resource_limit');
        }
        $lambda = ExactValue::of($r['lambda'] ?? null)->rational();
        if ($lambda->isLessThanOrEqualTo(0) || $lambda->isGreaterThan('1000000')) {
            throw new InvalidArgumentException('positive_bounded_risk_lambda_required');
        }
        if (isset($p['risk']) || $p['allocation_mode'] !== 'continuous_linear' || ! in_array($p['objective'], ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS'], true)) {
            throw new InvalidArgumentException('quadratic_risk_requires_nominal_linear_response_maximum');
        }
        $a = [];
        foreach ($r['covariance'] as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $n) {
                throw new InvalidArgumentException('covariance_dimensions');
            }$out = [];
            foreach ($row as $v) {
                if (strlen((string) $v) > 128) {
                    throw new InvalidArgumentException('quadratic_policy_precision_resource_limit');
                }
                $q = ExactValue::of($v, false, true)->rational();
                if ($q->abs()->isGreaterThan('1000000')) {
                    throw new InvalidArgumentException('covariance_magnitude');
                }$out[] = $q;
            }$a[] = $out;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (! $a[$i][$j]->isEqualTo($a[$j][$i])) {
                    throw new InvalidArgumentException('covariance_not_symmetric');
                }
            }
        }
        // Exact Schur complement elimination. A zero PSD pivot requires the entire remaining pivot row to be zero.
        for ($k = 0; $k < $n; $k++) {
            $pivot = $a[$k][$k];
            if ($pivot->isLessThan(0)) {
                throw new InvalidArgumentException('covariance_not_positive_semidefinite');
            }if ($pivot->isZero()) {
                for ($i = $k + 1; $i < $n; $i++) {
                    if (! $a[$k][$i]->isZero()) {
                        throw new InvalidArgumentException('covariance_not_positive_semidefinite');
                    }
                }

                continue;
            }for ($i = $k + 1; $i < $n; $i++) {
                for ($j = $i; $j < $n; $j++) {
                    $a[$i][$j] = $a[$i][$j]->minus($a[$i][$k]->multipliedBy($a[$k][$j])->dividedBy($pivot));
                    $a[$j][$i] = $a[$i][$j];
                }
            }
        }
    }

    public function evaluate(array $p, array $allocation, array $slopes): array
    {
        $r = $p['quadratic_risk'];
        $ids = $r['variable_order'];
        $lambda = BigRational::of($r['lambda']);
        $variance = BigRational::zero();
        $expected = BigRational::zero();
        $gradient = [];
        foreach ($ids as $i => $id) {
            $x = BigRational::of($allocation[$id]);
            $expected = $expected->plus($slopes[$i]->multipliedBy($x));
            $qx = BigRational::zero();
            foreach ($ids as $j => $other) {
                $qx = $qx->plus(BigRational::of($r['covariance'][$i][$j])->multipliedBy($allocation[$other]));
            }$variance = $variance->plus($x->multipliedBy($qx));
            $gradient[] = $slopes[$i]->minus($lambda->multipliedBy(2)->multipliedBy($qx));
        }
        $penalty = $variance->multipliedBy($lambda);

        return ['expected' => $expected, 'variance' => $variance, 'penalty' => $penalty, 'objective' => $expected->minus($penalty), 'gradient' => $gradient];
    }
}
