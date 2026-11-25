<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;

/** Comparisons of accepted frozen-model scenarios. Actual recorded values are never a modeled baseline. */
final class FrozenScenarioComparison
{
    public function build(array $result): array
    {
        $target = $result['scenarios']['optimized'] ?? null;
        if (! is_array($target) || ($target['check']['status'] ?? null) !== 'checked') {
            return ['status' => 'unavailable', 'reason' => 'checked_optimized_scenario_required'];
        }
        $comparisons = [];
        foreach (['current' => 'no_change_modeled', 'custom' => 'custom', 'same_budget_no_change_modeled' => 'same_budget_no_change_modeled'] as $key => $label) {
            $baseline = $result['scenarios'][$key] ?? null;
            if (! is_array($baseline) || ($baseline['check']['status'] ?? null) !== 'checked') {
                $comparisons[$label] = ['status' => 'unavailable', 'reason' => 'checked_baseline_required'];

                continue;
            }
            $before = BigDecimal::of($baseline['check']['spend_exact']);
            $after = BigDecimal::of($target['check']['spend_exact']);
            $delta = $after->minus($before);
            $spendFraction = $before->isZero() ? null : ExactValue::fraction($delta->toBigRational()->dividedBy($before));
            $responseBefore = $baseline['check']['response_approximate'];
            $responseAfter = $target['check']['response_approximate'];
            $changes = [];
            $l1 = BigDecimal::zero();
            foreach ($target['allocation'] as $id => $spend) {
                $d = BigDecimal::of($spend)->minus($baseline['allocation'][$id]);
                $changes[$id] = (string) $d;
                $l1 = $l1->plus($d->abs());
            }
            $comparisons[$label] = ['status' => 'compared_frozen_model_scenarios', 'baseline' => $label, 'candidate' => 'optimized', 'reference_policy' => $baseline['policy'] ?? null, 'reference_limits' => $baseline['limits'] ?? null,
                'baseline_spend_exact' => (string) $before, 'candidate_spend_exact' => (string) $after, 'spend_change_exact' => (string) $delta,
                'spend_change_fraction_exact' => $spendFraction, 'same_total_spend' => $before->isEqualTo($after),
                'baseline_response_approximate' => $responseBefore, 'candidate_response_approximate' => $responseAfter,
                'baseline_objective_approximate' => $baseline['check']['objective_approximate'] ?? null, 'candidate_objective_approximate' => $target['check']['objective_approximate'] ?? null, 'quadratic_risk_policy' => $result['problem']['quadratic_risk'] ?? null, 'response_change_approximate' => $responseAfter - $responseBefore, 'response_change_fraction_approximate' => $responseBefore > 0 ? ($responseAfter - $responseBefore) / $responseBefore : null,
                'allocation_changes_exact' => $changes, 'allocation_l1_change_exact' => (string) $l1,
                'target_optimization' => $result['problem']['target_optimization'] ?? null, 'causal_lift' => null, 'probability' => null];
        }

        return ['status' => 'computed_read_only', 'snapshot_id' => $result['snapshot_id'], 'comparisons' => $comparisons,
            'limits' => 'Compared under the same frozen models and constraints. Historical actuals stay separate. Unequal-spend comparisons are not silently normalized. The explicitly labeled same-budget reference scales current shares only if its original-domain check passes. Response changes are not causal lift, probabilities or guaranteed future improvement. A minimize-spend target or quadratic risk penalty may deliberately reduce response. Quadratic objective includes the declared covariance penalty; nominal response and stress ranges are not that objective or a calibrated risk.'];
    }
}
