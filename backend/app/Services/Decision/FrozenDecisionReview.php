<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;

/** Structured review candidates. A modeled allocation difference is never an instruction to apply it. */
final class FrozenDecisionReview
{
    public function build(array $result): array
    {
        $snapshot = $result['snapshot_id'];
        $base = ['version' => 'frozen-decision-review-v1', 'snapshot_id' => $snapshot, 'problem_id' => $result['problem_id'] ?? null,
            'action' => ['type' => 'SIMULATION_ONLY', 'operational_approval' => false],
            'confidence' => ['probability' => null, 'calibration' => 'not_established'],
            'limits' => ['Candidates are inspectable allocation differences under declared frozen models, not campaign instructions or validated opportunities.',
                'Severity, priority, probability and causal impact are unavailable. Item order follows declared variable order, not a ranking.',
                'Entity-level response changes do not establish portfolio improvement when other allocations, total spend or target policy differ. Exact spend is proposed budget, not an observed bill.']];
        $before = $result['scenarios']['current'] ?? null;
        $after = $result['scenarios']['optimized'] ?? null;
        if (($before['check']['status'] ?? null) !== 'checked' || ($after['check']['status'] ?? null) !== 'checked') {
            return $base + ['status' => 'unavailable', 'decision_state' => 'INSUFFICIENT_DATA',
                'reason' => 'checked_no_change_and_optimized_scenarios_required', 'items' => []];
        }
        $items = [];
        foreach ($result['problem']['variables'] as $index => $v) {
            $id = $v['id'];
            $current = BigDecimal::of($before['allocation'][$id]);
            $proposed = BigDecimal::of($after['allocation'][$id]);
            $delta = $proposed->minus($current);
            if ($delta->isZero()) {
                continue;
            }
            $pointer = '/scenarios/optimized/allocation/'.str_replace(['~', '/'], ['~0', '~1'], $id);
            $prior = $before['check']['parts'][$id]['response_approximate'] ?? null;
            $future = $after['check']['parts'][$id]['response_approximate'] ?? null;
            $items[] = ['id' => hash('sha256', RecordedLedger::canonical([$snapshot, $result['problem_id'] ?? null, $id, (string) $proposed])),
                'type' => 'BUDGET_REALLOCATION_REVIEW', 'entity' => array_intersect_key($v, array_flip(['id', 'platform', 'entity_level', 'entity_id'])),
                'severity' => null, 'priority' => null, 'observed' => ['type' => 'ACTUAL', 'metric' => 'recorded_spend', 'value_exact' => $result['actual']['entity_totals'][$id]['spend'] ?? null],
                'baseline' => ['type' => 'SCENARIO', 'metric' => 'no_change_budget', 'value_exact' => (string) $current],
                'proposed' => ['type' => 'SCENARIO', 'metric' => 'optimized_budget', 'value_exact' => (string) $proposed],
                'budget_change_exact' => (string) $delta, 'budget_change_fraction_exact' => $current->isZero() ? null : ExactValue::fraction($delta->toBigRational()->dividedBy($current)),
                'model' => ['name' => $result['model_snapshot'][$id]['curve']['name'] ?? null, 'version' => $result['model_snapshot'][$id]['model_version'] ?? null],
                'confidence' => $base['confidence'], 'action' => $base['action'],
                'expected_impact' => ['type' => 'SCENARIO', 'unit' => $result['problem']['response_unit'], 'baseline_response_approximate' => $prior,
                    'proposed_response_approximate' => $future, 'change_approximate' => $prior !== null && $future !== null ? $future - $prior : null,
                    'prediction_interval' => null, 'causal_lift' => null],
                'evidence' => ['snapshot_id' => $snapshot, 'allocation_pointer' => $pointer, 'variable_pointer' => '/problem/variables/'.$index,
                    'independent_check_pointer' => '/scenarios/optimized/check', 'certificate_pointer' => '/certificate']];
        }

        return $base + ['status' => 'computed_simulation_review', 'decision_state' => 'INSUFFICIENT_DATA',
            'decision_state_reason' => 'operational_readiness_and_calibrated_severity_not_established_even_when_simulation_is_checked',
            'candidate_count' => count($items), 'items' => $items, 'currency' => $result['problem']['currency'],
            'objective' => $result['problem']['objective'], 'target_optimization' => $result['problem']['target_optimization'] ?? null,
            'portfolio_comparison' => (new FrozenScenarioComparison)->build($result), 'certificate' => $result['certificate'] ?? null];
    }
}
