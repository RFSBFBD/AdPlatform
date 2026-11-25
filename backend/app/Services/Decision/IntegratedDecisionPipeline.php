<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Analytics\Curves\ResponseCurveContract;
use App\Services\Decision\Accounting\RecordedLedger;

/** One snapshot -> problem -> admission -> finite reference solve -> separate check -> scenarios -> audit. */
final class IntegratedDecisionPipeline
{
    public function fromLedger(RecordedLedger $ledger, DecisionProblem $problem, ?array $custom = null): array
    {
        if ($ledger->jsonSerialize()['scope']['provenance_kind'] !== 'synthetic_fixture') {
            return $this->run($ledger, $problem, [], $custom);
        }
        $built = (new LedgerModelBuilder)->build($ledger, $problem);
        $result = $this->run($ledger, $problem, $built['models'], $custom);
        $result['model_build'] = ['status' => $built['status'], 'failures' => $built['failures']];
        $result['consumers'] = (new DecisionSnapshotConsumers)->build($result);
        unset($result['audit_hash']);
        $result['audit_hash'] = hash('sha256', RecordedLedger::canonical($result));

        return $result;
    }

    public function run(RecordedLedger $ledger, DecisionProblem $problem, array $models, ?array $custom = null): array
    {
        $l = $ledger->jsonSerialize();
        $p = $problem->jsonSerialize();
        $route = (new AdaptiveModelRouter)->route($problem, $ledger, $models);
        $base = ['execution_manifest' => (new DecisionExecutionManifest)->capture(), 'pipeline_version' => 'integrated-reference-v1', 'runtime' => ['php' => PHP_VERSION, 'integer_size' => PHP_INT_SIZE, 'float_mantissa_bits' => 53, 'math_library' => 'brick/math1.x locked by composer.lock'], 'snapshot_id' => $l['snapshot_id'],
            'problem_id' => $problem->id(), 'actual' => $l, 'problem' => $p, 'route' => $route,
            'numerical_policy' => ['version' => 'numerical-reference-v1', 'binary64_input_absolute_loss_cap' => '0.0001', 'binary64_input_magnitude_cap' => '1000000000000', 'exact_money_feasibility_tolerance' => '0', 'modeled_objective' => 'approximate', 'source_precision' => 'preserve_or_reject', 'settlement_quantum' => null, 'display_rounding' => null],
            'operational_approval' => false, 'forecast' => ['status' => 'unavailable', 'reason' => 'independent_forecast_validation_required'],
            'probability_of_success' => null, 'limits' => ['Supported finite-menu/linear/separable-concave routes are unfinished portions of the full specification.',
                'Exact source arithmetic establishes neither source truth nor calibrated future outcomes.',
                'Model evidence supplied internally is not provider verification. Recorded-source bridge remains blocked.']];
        if (isset($p['primitive_shocks'])) {
            $base['primitive_scenarios'] = (new LedgerPrimitiveScenarios)->evaluate($ledger, $p['primitive_shocks']);
        }
        if (isset($p['forecast_horizon_days'])) {
            $base['forecast'] = (new RollingForecastEvidence)->evaluate($ledger, $p['response_metric'], $p['forecast_horizon_days']);
        }
        if ($route['status'] !== 'admitted') {
            return $this->seal($base + ['status' => 'abstained', 'scenarios' => []], $models);
        }
        $checker = new IndependentAllocationCheck;
        $externalCertificate = null;
        if (in_array($route['solver'], ['typed_quadratic', 'typed_finite_milp', 'typed_linear_lp', 'typed_concave'], true)) {
            $external = match ($route['solver']) {
                'typed_quadratic' => (new TypedQuadraticRiskSolver)->solve($problem, $models),'typed_linear_lp' => (new TypedLinearLpSolver)->solve($problem, $models),'typed_concave' => (new TypedConcaveSolver)->solve($problem, $models),default => (new TypedFiniteMilpSolver)->solve($problem, $models)
            };
            if ($external['status'] !== 'checked_numerical_solver') {
                return $this->seal($base + ['status' => 'solver_not_accepted', 'solver_result' => $external, 'scenarios' => []], $models);
            }
            $solved = ['allocation' => $external['allocation'], 'examined' => null, 'feasible' => null];
            $externalCertificate = $external['certificate'];
        } else {
            $solved = (new FiniteMenuReferenceSolver)->solve($problem, $models);
        }
        $bestAllocation = $solved['allocation'];
        $examined = $solved['examined'];
        $feasible = $solved['feasible'];
        $best = $bestAllocation === null ? null : $checker->check($problem, $models, $bestAllocation);
        if ($best !== null && $best['status'] !== 'checked') {
            return $this->seal($base + ['status' => 'independent_check_failed', 'scenarios' => [],
                'independent_check' => $best], $models);
        }
        $current = array_column($p['variables'], 'current_spend', 'id');
        $scenarios = ['current' => ['allocation' => $current, 'check' => $checker->check($problem, $models, $current, false)],
            'optimized' => $best === null ? null : ['allocation' => $bestAllocation, 'check' => $checker->check($problem, $models, $bestAllocation)],
            'custom' => $custom === null ? ['status' => 'not_requested'] : ['allocation' => $custom, 'check' => $checker->check($problem, $models, $custom)]];

        $scenarios['same_budget_no_change_modeled'] = (new SameBudgetNoChangeScenario)->build($problem, $models, $scenarios['optimized']);

        return $this->seal($base + ['status' => $best === null ? 'infeasible_on_declared_menu' : 'checked_reference_scenario',
            'scenarios' => $scenarios, 'certificate' => ['examined_menu_combinations' => $examined, 'feasible_menu_combinations' => $feasible,
                'scope' => $route['solver'] === 'typed_quadratic' ? 'declared_PSD_quadratic_risk_linear_response_tangent_bound' : ($externalCertificate === null ? 'all_declared_menu_points_enumerated' : ($route['solver'] === 'typed_concave' ? 'declared_continuous_separable_concave_numerical_tangent_bound' : ($route['solver'] === 'typed_linear_lp' ? 'declared_continuous_linear_domain_numerical_duality_certificate' : 'declared_finite_menu_numerical_solver_certificate'))), 'reference_ranking_arithmetic' => $solved['ranking_arithmetic'] ?? null, 'objective_guarantee' => $route['solver'] === 'typed_quadratic' ? 'exact_represented_rational_concave_quadratic_tangent_gap_not_empirical_risk_guarantee' : ($externalCertificate === null ? (($solved['ranking_arithmetic'] ?? '') === 'exact_rational_represented_linear_model' ? 'best_exact_represented_linear_objective_among_admitted_menu_points_not_empirical_or_future_optimum' : 'best_computed_objective_among_checked_menu_points_not_real_number_optimum') : ($route['solver'] === 'typed_concave' ? (($p['target_optimization'] ?? null) === 'minimize_spend' ? 'numerical_concave_target_spend_tangent_lower_bound_not_exact_real_optimum' : (in_array($p['objective'], ['MINIMIZE_CPA', 'MAXIMIZE_ROAS'], true) ? 'numerical_concave_fractional_tangent_bound_not_exact_real_optimum' : 'numerical_concave_tangent_upper_bound_not_exact_real_optimum')) : ($route['solver'] === 'typed_linear_lp' ? 'numerical_LP_fractional_certificate_not_exact_real_optimum' : 'numerical_MILP_fractional_certificate_over_menu_only'))),
                'budget_and_group_arithmetic' => 'exact_decimal', 'tie_policy' => $externalCertificate === null ? 'first_in_stable_variable_and_numeric_menu_order' : 'external_solver_ties_may_differ_by_runtime_version',
                'external_numerical_certificate' => $externalCertificate, 'recomputed_independently' => true]], $models);
    }

    private function seal(array $body, array $models): array
    {
        $frozen = [];
        foreach ($models as $id => $m) {
            $frozen[$id] = $m;
            unset($frozen[$id]['curve']);
            if (($m['curve'] ?? null) instanceof ResponseCurveContract) {
                $frozen[$id]['curve'] = ['name' => $m['curve']->name(), 'parameters' => $m['curve']->parameters(),
                    'support_max' => $m['curve']->supportMaxSpend(),
                    'support_min' => method_exists($m['curve'], 'supportMinSpend') ? $m['curve']->supportMinSpend() : 0.0];
            }
        }
        $body['model_snapshot'] = $frozen;
        $body['consumers'] = (new DecisionSnapshotConsumers)->build($body);
        $body['audit_hash'] = hash('sha256', RecordedLedger::canonical($body));

        if (isset($body['problem']['budget_shock_multipliers']) && $body['status'] === 'checked_reference_scenario') {
            $body['sensitivity'] = (new FrozenBudgetSensitivity)->analyze($body, $body['problem']['budget_shock_multipliers']);
            $body['consumers'] = (new DecisionSnapshotConsumers)->build($body);
            unset($body['audit_hash']);
            $body['audit_hash'] = hash('sha256', RecordedLedger::canonical($body));
        }

        return $body;
    }
}
