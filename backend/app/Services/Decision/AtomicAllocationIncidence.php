<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;

/** Derived platform partition and explicit overlapping constraint membership, not independent parent decisions. */
final class AtomicAllocationIncidence
{
    public function build(array $result): array
    {
        $variables = $result['problem']['variables'] ?? [];
        if ($variables === []) {
            return ['status' => 'unavailable', 'reason' => 'explicit_atomic_problem_required'];
        }
        $platforms = [];
        foreach ($variables as $variable) {
            $platforms[$variable['platform']][] = $variable['id'];
        }
        ksort($platforms, SORT_STRING);
        foreach ($platforms as &$members) {
            sort($members, SORT_STRING);
        }unset($members);
        $groups = [];
        foreach ($result['problem']['groups'] as $group) {
            $members = $group['members'];
            sort($members, SORT_STRING);
            $groups[$group['id']] = ['members' => $members, 'minimum' => $group['minimum'], 'maximum' => $group['maximum']];
        }
        ksort($groups, SORT_STRING);
        $scenarios = [];
        foreach ($result['scenarios'] ?? [] as $name => $scenario) {
            if (! is_array($scenario) || ($scenario['check']['status'] ?? null) !== 'checked') {
                $scenarios[$name] = ['status' => 'unavailable', 'reason' => 'checked_atomic_allocation_required'];

                continue;
            }
            $allocation = $scenario['allocation'];
            $expected = array_column($variables, 'id');
            if (array_diff(array_keys($allocation), $expected) || array_diff($expected, array_keys($allocation))) {
                $scenarios[$name] = ['status' => 'rejected_population_mismatch'];

                continue;
            }
            $platformTotals = [];
            $platformSum = BigDecimal::zero();
            foreach ($platforms as $platform => $members) {
                $total = $this->sum($allocation, $members);
                $platformTotals[$platform] = (string) $total;
                $platformSum = $platformSum->plus($total);
            }
            if (! $platformSum->isEqualTo($scenario['check']['spend_exact'])) {
                $scenarios[$name] = ['status' => 'rejected_platform_partition_conservation'];

                continue;
            }
            $groupTotals = [];
            foreach ($groups as $id => $group) {
                $sum = $this->sum($allocation, $group['members']);
                $groupTotals[$id] = ['spend_exact' => (string) $sum, 'within_bounds' => ! $sum->isLessThan($group['minimum']) && ! $sum->isGreaterThan($group['maximum'])];
            }
            $scenarios[$name] = ['status' => 'derived_checked_atomic_rollups', 'type' => 'SCENARIO', 'platform_spend_exact' => $platformTotals,
                'platform_partition_sum_exact' => (string) $platformSum, 'matches_checked_total' => true, 'constraint_group_spend' => $groupTotals];
        }

        return ['version' => 'atomic-allocation-incidence-v1', 'snapshot_id' => $result['snapshot_id'], 'problem_id' => $result['problem_id'] ?? null,
            'status' => 'computed_atomic_membership', 'atomic_entity_level' => $variables[0]['entity_level'], 'platform_membership' => $platforms,
            'constraint_group_membership' => $groups, 'scenarios' => $scenarios, 'independent_parent_variables' => false,
            'incidence_id' => hash('sha256', RecordedLedger::canonical([$platforms, $groups])),
            'limits' => ['Platform totals partition declared atomic budgets, not an additional layer to add to campaign/entity spend.',
                'Constraint groups may overlap and must never be summed into a portfolio total or interpreted as deduplicated audience populations.',
                'Membership is declared by the checked problem, not verified provider hierarchy, audience overlap, attribution or identified response interaction.',
                'No new decision variables, parent response curve, unique customer count or operational action is created.']];
    }

    private function sum(array $allocation, array $members): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($members as $id) {
            $sum = $sum->plus($allocation[$id]);
        }

        return $sum;
    }
}
