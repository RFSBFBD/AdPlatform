<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Declared tree rollup constraints over atomic budgets. Parent budgets are sums, never duplicated response variables. */
final class DeclaredHierarchyConstraints
{
    public const PREFIX = 'hierarchy:';

    public function normalize(array $p): array
    {
        $h = $p['hierarchy'];
        if (! is_array($h) || array_diff(array_keys($h), ['version', 'evidence_id', 'source_verification', 'nodes']) || ($h['version'] ?? null) !== 'declared-atomic-budget-tree-v1' || ($h['source_verification'] ?? null) !== 'declared_not_provider_verified' || ! is_string($h['evidence_id'] ?? null) || $h['evidence_id'] === '' || ! is_array($h['nodes'] ?? null) || ! array_is_list($h['nodes']) || count($h['nodes']) < 1 || count($h['nodes']) > 100) {
            throw new InvalidArgumentException('explicit_declared_hierarchy_required');
        }
        $atomic = array_column($p['variables'], 'id');
        $nodes = [];
        $boundAtoms = [];
        foreach ($h['nodes'] as $node) {
            if (! is_array($node) || array_diff(array_keys($node), ['id', 'parent_id', 'atomic_variable_id', 'minimum', 'maximum']) || ! is_string($node['id'] ?? null) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $node['id']) || isset($nodes[$node['id']]) || ! array_key_exists('parent_id', $node) || ! array_key_exists('atomic_variable_id', $node)) {
                throw new InvalidArgumentException('invalid_hierarchy_node');
            }
            if ($node['parent_id'] !== null && ! is_string($node['parent_id'])) {
                throw new InvalidArgumentException('invalid_hierarchy_parent');
            }
            if ($node['atomic_variable_id'] !== null) {
                if (! is_string($node['atomic_variable_id']) || ! in_array($node['atomic_variable_id'], $atomic, true) || isset($boundAtoms[$node['atomic_variable_id']])) {
                    throw new InvalidArgumentException('hierarchy_atomic_identity_mismatch');
                }$boundAtoms[$node['atomic_variable_id']] = true;
            }
            $lo = ExactValue::of($node['minimum'] ?? null)->decimal();
            $hi = ExactValue::of($node['maximum'] ?? null)->decimal();
            if ($lo->isGreaterThan($hi)) {
                throw new InvalidArgumentException('hierarchy_inverted_bounds');
            }$nodes[$node['id']] = $node;
        }
        if (array_diff($atomic, array_keys($boundAtoms))) {
            throw new InvalidArgumentException('hierarchy_must_cover_each_atomic_variable_once');
        }
        $children = [];
        foreach ($nodes as $id => $node) {
            $parent = $node['parent_id'];
            if ($parent !== null) {
                if (! isset($nodes[$parent]) || $parent === $id) {
                    throw new InvalidArgumentException('hierarchy_parent_missing_or_self');
                }$children[$parent][] = $id;
            }
        }
        $roots = array_keys(array_filter($nodes, fn ($n) => $n['parent_id'] === null));
        if (count($roots) !== 1) {
            throw new InvalidArgumentException('hierarchy_single_root_required');
        }
        $visiting = [];
        $done = [];
        $members = [];
        $walk = function (string $id) use (&$walk, &$visiting, &$done, &$members, $nodes, $children): array {
            if (isset($visiting[$id])) {
                throw new InvalidArgumentException('hierarchy_cycle');
            }if (isset($done[$id])) {
                return $members[$id];
            }$visiting[$id] = true;
            $node = $nodes[$id];
            $kids = $children[$id] ?? [];
            if ($node['atomic_variable_id'] !== null) {
                if ($kids !== []) {
                    throw new InvalidArgumentException('hierarchy_atomic_node_must_be_leaf');
                }$m = [$node['atomic_variable_id']];
            } else {
                if ($kids === []) {
                    throw new InvalidArgumentException('hierarchy_parent_needs_atomic_descendants');
                }$m = [];
                foreach ($kids as $child) {
                    $m = array_merge($m, $walk($child));
                }
            }
            unset($visiting[$id]);
            $done[$id] = true;
            sort($m, SORT_STRING);

            return $members[$id] = $m;
        };
        foreach (array_keys($nodes) as $id) {
            $walk($id);
        }
        if (count($done) !== count($nodes) || count($walk($roots[0])) !== count($atomic)) {
            throw new InvalidArgumentException('hierarchy_disconnected');
        }
        $generated = [];
        foreach ($nodes as $id => $node) {
            $generated[] = ['id' => self::PREFIX.$id, 'members' => $members[$id], 'minimum' => $node['minimum'], 'maximum' => $node['maximum']];
        }
        $regular = [];
        $supplied = [];
        foreach ($p['groups'] as $g) {
            if (! is_array($g) || ! is_string($g['id'] ?? null)) {
                throw new InvalidArgumentException('invalid_group_before_hierarchy_compilation');
            }
            if (str_starts_with($g['id'], self::PREFIX)) {
                $supplied[] = $g;
            } else {
                $regular[] = $g;
            }
        }
        // Frozen reconstruction may already carry these exact compiled groups. Never accept changed/partial reserved rows.
        if ($supplied !== [] && RecordedLedger::canonical($supplied) !== RecordedLedger::canonical($generated)) {
            throw new InvalidArgumentException('hierarchy_compiled_constraint_mismatch');
        }
        if (count($regular) + count($generated) > 100) {
            throw new InvalidArgumentException('hierarchy_constraint_resource_limit');
        }
        $p['groups'] = array_merge($regular, $generated);

        return $p;
    }

    public function evidence(array $result): array
    {
        $h = $result['problem']['hierarchy'] ?? null;
        if ($h === null) {
            return ['status' => 'unavailable', 'snapshot_id' => $result['snapshot_id'], 'reason' => 'declared_hierarchy_not_supplied'];
        }
        $groups = [];
        foreach ($result['problem']['groups'] as $g) {
            if (str_starts_with($g['id'], self::PREFIX)) {
                $groups[substr($g['id'], strlen(self::PREFIX))] = $g;
            }
        }
        $rows = [];
        foreach ($result['scenarios'] ?? [] as $name => $s) {
            if (($s['check']['status'] ?? null) !== 'checked') {
                continue;
            }$totals = [];
            foreach ($groups as $id => $g) {
                $sum = BigDecimal::zero();
                foreach ($g['members'] as $member) {
                    $sum = $sum->plus($s['allocation'][$member]);
                }$totals[$id] = (string) $sum;
            }$rows[$name] = ['status' => 'derived_checked_atomic_rollups', 'node_spend_exact' => $totals];
        }

        return ['status' => 'declared_tree_compiled_to_atomic_sum_constraints', 'snapshot_id' => $result['snapshot_id'], 'evidence_id' => $h['evidence_id'], 'source_verification' => $h['source_verification'], 'nodes' => $h['nodes'], 'constraints' => $groups, 'scenarios' => $rows, 'independent_parent_variables' => false, 'limits' => ['Each atomic variable occurs once as a leaf. Every parent budget is the exact sum of descendant atomic budgets, never extra spend or a response added again.', 'This declared tree is not verified provider hierarchy, audience overlap, identity resolution or response interaction. All response functions remain atomic.', 'Node rows across hierarchy levels overlap and must never be added into portfolio totals. No campaign changes.']];
    }
}
