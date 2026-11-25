<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\Curves\ResponseCurveContract;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;

/** Route by declared structure and data evidence. No unvalidated family is a fallback. */
final class AdaptiveModelRouter
{
    public function route(DecisionProblem $problem, RecordedLedger $ledger, array $models): array
    {
        $p = $problem->jsonSerialize();
        $l = $ledger->jsonSerialize();
        $scope = $l['scope'];
        $reasons = [];
        foreach (['currency', 'outcome_definition', 'reporting_window', 'timezone', 'population', 'outcome_kind'] as $key) {
            if ($p[$key] !== $scope[$key]) {
                $reasons[] = 'problem_source_scope_mismatch:'.$key;
            }
        }
        if ($p['source_snapshot_id'] !== $l['snapshot_id']) {
            $reasons[] = 'problem_snapshot_mismatch';
        }
        if ($scope['provenance_kind'] !== 'synthetic_fixture') {
            $reasons[] = 'recorded_source_bridge_not_yet_qualified';
        }
        $expectedIds = array_column($p['variables'], 'id');
        if (array_diff(array_keys($l['entity_totals']), $expectedIds) || array_diff($expectedIds, array_keys($l['entity_totals']))) {
            $reasons[] = 'ledger_problem_population_mismatch';
        }
        if (array_diff(array_keys($models), $expectedIds) || array_diff($expectedIds, array_keys($models))) {
            $reasons[] = 'model_population_mismatch';
        }
        $rationale = [];
        foreach ($p['variables'] as $v) {
            $m = $models[$v['id']] ?? [];
            if (($l['entity_totals'][$v['id']][$p['response_metric']] ?? null) === null) {
                $reasons[] = 'required_response_not_recorded:'.$v['id'];
            }
            if (! isset($l['entity_totals'][$v['id']]) || ! BigDecimal::of($v['current_spend'])->isEqualTo($l['entity_totals'][$v['id']]['spend'])) {
                $reasons[] = 'current_spend_ledger_mismatch:'.$v['id'];
            }
            $context = ['objective' => $p['objective'], 'platform' => $v['platform'], 'campaign_type' => $v['campaign_type'],
                'data_volume' => $m['data_volume'] ?? null, 'data_quality' => $m['data_quality'] ?? null,
                'variance' => $m['variance'] ?? null, 'history_days' => $m['history_days'] ?? null,
                'response_shape' => $m['response_shape'] ?? null, 'constraints' => ['domain' => $p['allocation_mode'], 'groups' => $p['groups']]];
            $rationale[$v['id']] = $context;
            foreach (['data_volume', 'data_quality', 'variance', 'history_days', 'response_shape', 'validation',
                'period_days', 'source_snapshot_id', 'objective', 'platform', 'campaign_type', 'model_version', 'source_admission'] as $key) {
                if (! array_key_exists($key, $m)) {
                    $reasons[] = 'model_evidence_required:'.$v['id'].':'.$key;
                }
            }
            if (! ($m['curve'] ?? null) instanceof ResponseCurveContract) {
                $reasons[] = 'validated_curve_required:'.$v['id'];
            } else {
                if (($m['response_shape'] ?? null) !== $m['curve']->name()) {
                    $reasons[] = 'declared_response_shape_mismatch:'.$v['id'];
                }
            }
            foreach (['objective' => $p['objective'], 'platform' => $v['platform'], 'campaign_type' => $v['campaign_type'],
                'source_snapshot_id' => $l['snapshot_id'], 'period_days' => $p['horizon_days']] as $key => $expected) {
                if (($m[$key] ?? null) !== $expected) {
                    $reasons[] = 'model_context_mismatch:'.$v['id'].':'.$key;
                }
            }
            if ($p['allocation_mode'] === 'continuous_linear' && (($m['response_shape'] ?? null) !== 'linear' || ! ($m['curve'] ?? null) instanceof ResponseCurveContract || $m['curve']->name() !== 'linear')) {
                $reasons[] = 'continuous_linear_requires_linear_response:'.$v['id'];
            }
            if ($p['allocation_mode'] === 'continuous_concave' && (! ($m['curve'] ?? null) instanceof ResponseCurveContract || ! in_array($m['curve']->name(), ['linear', 'log', 'power', 'saturation', 'quadratic_spline'], true) || ($m['curve']->name() === 'power' && (float) $v['minimum'] < 1e-9) || ($m['curve']->name() === 'quadratic_spline' && $m['curve']->parameters()['b'] <= 0))) {
                $reasons[] = 'unsupported_continuous_concave_shape_or_boundary:'.$v['id'];
            }
            $validation = $m['validation'] ?? [];
            if (($validation['status'] ?? '') !== 'passed' || ! isset($validation['method'], $validation['evidence_id'])
                || ! is_string($validation['evidence_id']) || $validation['evidence_id'] === '') {
                $reasons[] = 'model_validation_not_established:'.$v['id'];
            }
            if (! is_int($m['data_volume'] ?? null) || $m['data_volume'] < 1 || ! is_int($m['history_days'] ?? null) || $m['history_days'] < 1
                || ! is_array($m['data_quality'] ?? null) || ($m['data_quality']['hard_gates'] ?? '') !== 'passed'
                || ! isset($m['data_quality']['policy_version']) || ! is_numeric($m['variance'] ?? null)
                || ! is_finite((float) $m['variance']) || (float) $m['variance'] < 0) {
                $reasons[] = 'model_data_admission_failed:'.$v['id'];
            }
            $synthetic = $scope['provenance_kind'] === 'synthetic_fixture';
            if (($m['source_admission'] ?? '') !== ($synthetic ? 'synthetic_fixture' : 'verified_source_contract')) {
                $reasons[] = 'source_admission_not_established:'.$v['id'];
            }
            // No caller label can lift an established provider prohibition.
            if (! $synthetic && in_array($v['platform'], ['snapchat', 'meta'], true)) {
                $reasons[] = 'established_provider_source_block:'.$v['platform'];
            }
            if (isset($m['variance_policy']) && ($m['variance_policy']['state'] ?? '') === 'highly_volatile' && ! isset($p['risk'])) {
                $reasons[] = 'robust_uncertainty_contract_required:'.$v['id'];
            }
        }
        $work = 1;
        $large = false;
        $points = array_sum(array_map(fn ($v) => count($v['menu']), $p['variables']));
        if ($points > 10000) {
            $reasons[] = 'solver_menu_point_resource_limit';
        }
        foreach ($p['variables'] as $v) {
            $work *= count($v['menu']);
            if ($work > 100000) {
                $large = true;
                break;
            }
        }
        if ($p['allocation_mode'] === 'continuous_concave' && count($p['variables']) > 40) {
            $reasons[] = 'concave_dimension_resource_limit';
        }
        $riskRoute = isset($p['quadratic_risk']) ? 'typed_quadratic' : ($p['allocation_mode'] === 'continuous_concave' ? 'typed_concave' : ($p['allocation_mode'] === 'continuous_linear' ? 'typed_linear_lp' : ($large ? 'typed_finite_milp' : (isset($p['risk']) ? 'box_robust_finite_menu_exhaustive' : 'finite_menu_exhaustive'))));

        return ['status' => $reasons ? 'unsupported_or_insufficient_evidence' : 'admitted',
            'solver' => $reasons ? null : $riskRoute, 'solver_version' => isset($p['quadratic_risk']) ? 'typed-quadratic-risk-v1' : ($p['allocation_mode'] === 'continuous_concave' ? 'typed-concave-tangent-v1' : ($p['allocation_mode'] === 'continuous_linear' ? 'typed-linear-lp-v1' : ($large ? 'typed-finite-milp-v1' : 'finite-menu-reference-v1'))),
            'reasons' => array_values(array_unique($reasons)), 'rationale' => $rationale,
            'claim' => isset($p['quadratic_risk']) ? 'concave_PSD_quadratic_declared_risk_objective_exact_represented_tangent_gap_not_calibrated_risk' : ($p['allocation_mode'] === 'continuous_concave' ? 'numerical_separable_concave_supporting_tangent_bound_no_exact_real_or_future_guarantee' : ($p['allocation_mode'] === 'continuous_linear' ? 'numerical_linear_program_with_independent_duality_check_no_exact_real_or_future_guarantee' : 'global_over_declared_finite_menu_only; modeled objective approximate; no causal or future guarantee')),
            'source_state' => $scope['provenance_kind'], 'operational_approval' => false];
    }
}
