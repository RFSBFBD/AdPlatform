<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\IntegratedDecisionPipeline;
use Tests\TestCase;

final class DeclaredHierarchyIntegratedTest extends TestCase
{
    private function fixture(): array
    {
        require_once __DIR__.'/ConcaveIntegratedDecisionTest.php';
        $t = new ConcaveIntegratedDecisionTest('test_analytic_log_allocation_has_independent_tangent_bound_and_frozen_replay');
        [$l,$p,$m] = (new \ReflectionMethod($t, 'fixtureProblem'))->invoke($t);
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        $p['hierarchy'] = ['version' => 'declared-atomic-budget-tree-v1', 'evidence_id' => 'synthetic-declared-tree', 'source_verification' => 'declared_not_provider_verified', 'nodes' => [
            ['id' => 'portfolio', 'parent_id' => null, 'atomic_variable_id' => null, 'minimum' => '0', 'maximum' => '10'], ['id' => 'campaign-group', 'parent_id' => 'portfolio', 'atomic_variable_id' => null, 'minimum' => '0', 'maximum' => '6'], ['id' => 'leaf-a', 'parent_id' => 'campaign-group', 'atomic_variable_id' => 'a', 'minimum' => '0', 'maximum' => '10'], ['id' => 'leaf-b', 'parent_id' => 'portfolio', 'atomic_variable_id' => 'b', 'minimum' => '0', 'maximum' => '10']]];

        return [$l, $p, $m];
    }

    public function test_parent_constraints_compile_to_atomic_sums_without_double_counting_and_replay(): void
    {
        [$l,$p,$m] = $this->fixture();
        $problem = new DecisionProblem($p);
        $r = (new IntegratedDecisionPipeline)->run($l, $problem, $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertSame('6', $r['scenarios']['optimized']['allocation']['a']);
        self::assertSame('4', $r['scenarios']['optimized']['allocation']['b']);
        self::assertSame('10', $r['consumers']['hierarchy']['scenarios']['optimized']['node_spend_exact']['portfolio']);
        self::assertSame('6', $r['consumers']['hierarchy']['scenarios']['optimized']['node_spend_exact']['campaign-group']);
        self::assertFalse($r['consumers']['hierarchy']['independent_parent_variables']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_consumers']);
        $restored = $problem->jsonSerialize();
        unset($restored['problem_version']);
        self::assertSame($problem->id(), (new DecisionProblem($restored))->id());
        $p['hierarchy']['nodes'][0]['maximum'] = '2';
        $p['minimum_spend'] = '3';
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('solver_not_accepted', $r['status']);
    }

    public function test_tree_compilation_survives_linear_menu_quadratic_and_sensitivity_routes(): void
    {
        foreach (['continuous_linear', 'finite_menu', 'quadratic'] as $mode) {
            [$l,$p,$m] = $this->fixture();
            $p['allocation_mode'] = $mode === 'quadratic' ? 'continuous_linear' : $mode;
            foreach ($m as $id => &$model) {
                $model['curve'] = ResponseCurve::linear($id === 'a' ? 2. : 1., 10.);
                $model['response_shape'] = 'linear';
            }unset($model);
            if ($mode === 'finite_menu') {
                foreach ($p['variables'] as &$v) {
                    $v['menu'] = ['0', '2', '4', '6', '8', '10'];
                }
            }unset($v);
            if ($mode === 'quadratic') {
                $p['quadratic_risk'] = ['mode' => 'response_slope_covariance_penalty', 'lambda' => '0.1', 'lambda_unit' => 'inverse_response_unit', 'covariance_unit' => 'response_unit_squared_per_currency_squared', 'evidence_id' => 'synthetic', 'variable_order' => ['a', 'b'], 'covariance' => [['1', '0'], ['0', '1']]];
            }
            $p['budget_shock_multipliers'] = ['1.1'];
            $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
            self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
            self::assertEqualsWithDelta(6., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-6);
            self::assertEqualsWithDelta(4., (float) $r['scenarios']['optimized']['allocation']['b'], 1e-6);
            self::assertSame('evaluated_budget_shocks', $r['sensitivity']['status']);
            self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_scenarios']);
        }
    }

    public function test_cycles_duplicate_leaves_missing_parents_and_tampered_compiled_groups_refuse(): void
    {
        [$l,$p,$m] = $this->fixture();
        $cases = [];
        $b = $p;
        $b['hierarchy']['nodes'][1]['parent_id'] = 'leaf-a';
        $cases[] = $b;
        $b = $p;
        $b['hierarchy']['nodes'][3]['atomic_variable_id'] = 'a';
        $cases[] = $b;
        $b = $p;
        $b['hierarchy']['nodes'][3]['parent_id'] = 'missing';
        $cases[] = $b;
        $b = (new DecisionProblem($p))->jsonSerialize();
        unset($b['problem_version']);
        $b['groups'][0]['maximum'] = '999';
        $cases[] = $b;
        foreach ($cases as $b) {
            try {
                new DecisionProblem($b);
                self::fail('invalid tree accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
