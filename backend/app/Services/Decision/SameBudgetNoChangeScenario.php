<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;

/** Fixed current budget shares at optimized total, never a new solve or a historical outcome. */
final class SameBudgetNoChangeScenario
{
    public function build(DecisionProblem $problem, array $models, ?array $optimized): array
    {
        $base = ['policy' => 'proportional_current_shares_at_checked_optimized_total_v1',
            'type' => 'SCENARIO', 'limits' => 'A declared fixed-share model reference, not historical actuals or an owner-selected allocation. Original domain, target, bounds and group constraints still apply. No rounding to force feasibility. Not a separate optimization or causal control.'];
        if (($optimized['check']['status'] ?? null) !== 'checked') {
            return $base + ['status' => 'unavailable', 'reason' => 'checked_optimized_scenario_required'];
        }
        $p = $problem->jsonSerialize();
        $total = BigDecimal::zero();
        foreach ($p['variables'] as $v) {
            $total = $total->plus($v['current_spend']);
        }
        if ($total->isZero()) {
            return $base + ['status' => 'unavailable', 'reason' => 'positive_current_spend_required_for_defined_shares'];
        }
        $target = BigDecimal::of($optimized['check']['spend_exact']);
        $factor = $target->toBigRational()->dividedBy($total);
        $allocation = [];
        foreach ($p['variables'] as $v) {
            $rational = BigRational::of($v['current_spend'])->multipliedBy($factor);
            try {
                $allocation[$v['id']] = (string) $rational->toBigDecimal();
            } catch (RoundingNecessaryException) {
                return $base + ['status' => 'unavailable', 'reason' => 'proportional_allocation_requires_nonterminating_decimal_no_rounding_policy', 'scale_exact' => ExactValue::fraction($factor)];
            }
        }
        $check = (new IndependentAllocationCheck)->check($problem, $models, $allocation);
        if ($check['status'] !== 'checked') {
            return $base + ['status' => 'unavailable', 'reason' => 'proportional_reference_violates_original_problem', 'allocation' => $allocation, 'check' => $check, 'scale_exact' => ExactValue::fraction($factor)];
        }
        if (! BigDecimal::of($check['spend_exact'])->isEqualTo($target)) {
            return $base + ['status' => 'unavailable', 'reason' => 'exact_total_conservation_failed'];
        }

        return $base + ['status' => 'checked_fixed_share_reference', 'allocation' => $allocation, 'check' => $check,
            'scale_exact' => ExactValue::fraction($factor), 'reference_current_spend_exact' => (string) $total, 'matched_optimized_spend_exact' => (string) $target];
    }
}
