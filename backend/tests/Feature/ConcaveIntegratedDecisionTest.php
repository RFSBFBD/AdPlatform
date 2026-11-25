<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\IntegratedDecisionPipeline;
use App\Services\Decision\LinearDualResidualBound;
use Brick\Math\BigDecimal;
use Tests\TestCase;

final class ConcaveIntegratedDecisionTest extends TestCase
{
    private function fixtureProblem(): array
    {
        $scope = ['provider' => 'synthetic', 'account_id' => 'synthetic', 'source_contract' => 'synthetic-1', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'reporting_window' => '2026-11-01/2026-11-01', 'outcome_definition' => 'synthetic-credit', 'outcome_kind' => 'other_explicit', 'attribution' => 'synthetic', 'population' => 'a+b',
            'conversion_count_kind' => 'fractional_credit', 'provenance_kind' => 'synthetic_fixture'];
        $rows = [];
        foreach (['a', 'b'] as $id) {
            $rows[] = ['key' => $id, 'variable_id' => $id, 'source_ref' => 'synthetic:'.$id, 'reporting_date' => '2026-11-01', 'scope' => $scope, 'spend' => '5', 'conversions' => '1'];
        }
        $ledger = new RecordedLedger($scope, $rows);
        $snapshot = $ledger->jsonSerialize();
        $spec = array_intersect_key($scope, array_flip(['currency', 'timezone', 'reporting_window', 'outcome_definition', 'outcome_kind', 'population'])) + [
            'objective' => 'MAXIMIZE_CONVERSIONS', 'source_snapshot_id' => $snapshot['snapshot_id'], 'response_metric' => 'conversions', 'response_unit' => 'synthetic-credit', 'business_estimand' => 'synthetic',
            'budget_semantics' => 'period_total_not_daily_rate', 'budget_cap' => '10', 'minimum_spend' => '0', 'minimum_response' => '0', 'horizon_days' => 1, 'allocation_mode' => 'continuous_concave', 'interaction_model' => 'separable_no_overlap_claim',
            'groups' => [], 'variables' => []];
        $models = [];
        foreach (['a' => 2., 'b' => 1.] as $id => $a) {
            $spec['variables'][] = ['id' => $id, 'platform' => 'synthetic', 'campaign_type' => 'synthetic', 'entity_level' => 'campaign', 'entity_id' => $id, 'current_spend' => '5', 'minimum' => '0', 'maximum' => '10', 'menu' => []];
            $models[$id] = ['curve' => ResponseCurve::log($a, 1., 10.), 'source_snapshot_id' => $snapshot['snapshot_id'], 'objective' => 'MAXIMIZE_CONVERSIONS', 'platform' => 'synthetic', 'campaign_type' => 'synthetic', 'period_days' => 1,
                'data_volume' => 30, 'data_quality' => ['hard_gates' => 'passed', 'policy_version' => 'synthetic-only'], 'variance' => 1., 'history_days' => 30, 'response_shape' => 'log', 'model_version' => 'synthetic-log-1', 'source_admission' => 'synthetic_fixture',
                'validation' => ['status' => 'passed', 'method' => 'known-analytic-synthetic', 'evidence_id' => 'synthetic']];
        }

        return [$ledger, $spec, $models];
    }

    public function test_analytic_log_allocation_has_independent_tangent_bound_and_frozen_replay(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('typed_concave', $r['route']['solver']);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(7., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
        self::assertEqualsWithDelta(3., (float) $r['scenarios']['optimized']['allocation']['b'], 1e-4);
        self::assertTrue(BigDecimal::of($r['scenarios']['optimized']['check']['spend_exact'])->isEqualTo('10'));
        self::assertSame('checked_fixed_share_reference', $r['scenarios']['same_budget_no_change_modeled']['status']);
        self::assertTrue($r['consumers']['charts']['scenario_comparison']['comparison']['comparisons']['same_budget_no_change_modeled']['same_total_spend']);
        $cert = $r['certificate']['external_numerical_certificate'];
        self::assertLessThan(1e-5, $cert['gap_approximate']);
        self::assertGreaterThanOrEqual($r['scenarios']['optimized']['check']['response_approximate'] - 1e-10, $cert['upper_bound_approximate']);
        self::assertEqualsWithDelta(2 * log(8) + log(4), $r['scenarios']['optimized']['check']['response_approximate'], 1e-9);
        $decoded = json_decode(RecordedLedger::canonical($r), true, 128, JSON_THROW_ON_ERROR);
        self::assertTrue((new DecisionEvidenceReplay)->replay($decoded)['matches_certificate']);
        $replay = (new DecisionEvidenceReplay)->replay($r);
        self::assertTrue($replay['matches_consumers']);
        self::assertTrue($replay['matches_certificate']);
        config(['solver.python' => '']);
        $unavailable = (new DecisionEvidenceReplay)->replay($r);
        self::assertSame('recomputed_mismatch', $unavailable['status']);
        self::assertFalse($unavailable['matches_scenarios']);
        self::assertFalse($unavailable['matches_status']);
        self::assertFalse($unavailable['matches_certificate']);
        self::assertSame('checked_reference_scenario', $r['status']);
    }

    public function test_overlapping_group_constraint_changes_analytic_optimum_and_power_zero_boundary_refuses(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $spec['groups'] = [['id' => 'a-cap', 'members' => ['a'], 'minimum' => '0', 'maximum' => '6'], ['id' => 'both', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => '10']];
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertSame('6', $r['scenarios']['optimized']['allocation']['a']);
        self::assertSame('4', $r['scenarios']['optimized']['allocation']['b']);
        $models['a']['curve'] = ResponseCurve::power(2., .5, 10.);
        $models['a']['response_shape'] = 'power';
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('abstained', $r['status']);
        self::assertContains('unsupported_continuous_concave_shape_or_boundary:a', $r['route']['reasons']);
        $spec['variables'][0]['minimum'] = '0.000000000001';
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('abstained', $r['status']);
        self::assertContains('unsupported_continuous_concave_shape_or_boundary:a', $r['route']['reasons']);
    }

    public function test_symmetric_saturation_and_positive_power_and_explicit_robust_scaling(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        foreach (['saturation', 'power'] as $family) {
            [$ledger,$spec,$models] = $this->fixtureProblem();
            foreach (['a', 'b'] as $id) {
                $models[$id]['curve'] = $family === 'saturation' ? ResponseCurve::saturation(10., .2, 10., 1.) : ResponseCurve::power(2., .5, 10., 1.);
                $models[$id]['response_shape'] = $family;
            }
            foreach ($spec['variables'] as &$v) {
                $v['minimum'] = '1';
            }unset($v);
            $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
            self::assertEqualsWithDelta(5., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
            self::assertEqualsWithDelta(5., (float) $r['scenarios']['optimized']['allocation']['b'], 1e-4);
            self::assertSame('bounded', $r['certificate']['external_numerical_certificate']['tangent_dual_residual_bound']['status']);
        }
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $spec['risk'] = ['mode' => 'independent_response_scale_box', 'evidence_id' => 'synthetic', 'bounds' => ['a' => ['lower_scale' => '0.5', 'upper_scale' => '1'], 'b' => ['lower_scale' => '1', 'upper_scale' => '1']]];
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(5., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
        self::assertNull($r['scenarios']['optimized']['check']['stress']['probability']);
    }

    public function test_continuous_c1_quadratic_spline_interior_and_linear_tail_are_bound_checked_and_replayed(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        [$ledger,$spec,$models] = $this->fixtureProblem();
        foreach (['a' => 1.5, 'b' => 1.] as $id => $a) {
            $models[$id]['curve'] = ResponseCurve::quadraticSpline($a, .5, 10., 20.);
            $models[$id]['response_shape'] = 'quadratic_spline';
        }
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(8., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
        self::assertEqualsWithDelta(2., (float) $r['scenarios']['optimized']['allocation']['b'], 1e-4);
        self::assertEqualsWithDelta(11.5, $r['scenarios']['optimized']['check']['response_approximate'], 1e-7);
        self::assertLessThan(1e-5, $r['certificate']['external_numerical_certificate']['gap_approximate']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
        $spec['groups'] = [['id' => 'a-cap', 'members' => ['a'], 'minimum' => '0', 'maximum' => '7']];
        $limited = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $limited['status']);
        self::assertSame('7', $limited['scenarios']['optimized']['allocation']['a']);
        self::assertSame('3', $limited['scenarios']['optimized']['allocation']['b']);
        $daily = ResponseCurve::quadraticSpline(2., .25, 4., 12.);
        foreach ([0., 4., 8.] as $x) {
            self::assertEqualsWithDelta(7 * $daily->revenueAt($x), $daily->overDays(7)->revenueAt(7 * $x), 1e-10);
            self::assertEqualsWithDelta($daily->marginalAt($x), $daily->overDays(7)->marginalAt(7 * $x), 1e-10);
        }
        $spec['groups'] = [];
        $spec['budget_cap'] = '30';
        foreach ($spec['variables'] as &$v) {
            $v['maximum'] = '20';
        }
        unset($v);
        foreach (['a', 'b'] as $id) {
            $models[$id]['curve'] = ResponseCurve::quadraticSpline(1., .5, 10., 20.);
        }
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(20., $r['scenarios']['optimized']['check']['response_approximate'], 1e-7);
        self::assertTrue(BigDecimal::of($r['scenarios']['optimized']['check']['spend_exact'])->isEqualTo('30'));
        $models['a']['curve'] = ResponseCurve::quadraticSpline(1., 0., 10., 20.);
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('abstained', $r['status']);
    }

    public function test_concave_response_floor_and_explicit_revenue_target_maximize_response_use_original_candidate_checks(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $spec['minimum_response'] = '5';
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(7., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
        self::assertSame('checked', $r['scenarios']['optimized']['check']['status']);
        $spec['minimum_response'] = '10';
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('solver_not_accepted', $r['status']);
        $spec['minimum_response'] = '0';
        $spec['objective'] = 'TARGET_REVENUE';
        $spec['target'] = '5';
        $spec['target_optimization'] = 'maximize_response';
        $spec['response_metric'] = 'conversion_value';
        $l = $ledger->jsonSerialize();
        $rows = [];
        foreach ($l['rows'] as $key => $row) {
            $rows[] = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $l['scope'], 'spend' => '5', 'conversions' => '1', 'conversion_value' => '1'];
        }
        $ledger = new RecordedLedger($l['scope'], $rows);
        $spec['source_snapshot_id'] = $ledger->jsonSerialize()['snapshot_id'];
        foreach ($models as &$model) {
            $model['objective'] = 'TARGET_REVENUE';
            $model['source_snapshot_id'] = $spec['source_snapshot_id'];
        }unset($model);
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(7., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
        self::assertSame('maximize_response', $r['scenarios']['optimized']['check']['optimization_measure']['target_optimization']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
        $spec['target'] = '10';
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('solver_not_accepted', $r['status']);
    }

    public function test_minimum_spend_concave_revenue_target_has_lower_bound_and_not_max_response_semantics(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $spec['objective'] = 'TARGET_REVENUE';
        $spec['response_metric'] = 'conversion_value';
        $spec['target'] = '2';
        $spec['target_optimization'] = 'minimize_spend';
        $l = $ledger->jsonSerialize();
        $rows = [];
        foreach ($l['rows'] as $key => $row) {
            $rows[] = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $l['scope'], 'spend' => '5', 'conversion_value' => '1'];
        }
        $ledger = new RecordedLedger($l['scope'], $rows);
        $spec['source_snapshot_id'] = $ledger->jsonSerialize()['snapshot_id'];
        foreach ($models as &$model) {
            $model['objective'] = 'TARGET_REVENUE';
            $model['source_snapshot_id'] = $spec['source_snapshot_id'];
            $model['curve'] = ResponseCurve::log(1., 1., 10.);
        }unset($model);
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(2 * (exp(1) - 1), (float) $r['scenarios']['optimized']['check']['spend_exact'], 1e-5);
        self::assertSame('minimize_spend', $r['scenarios']['optimized']['check']['optimization_measure']['target_optimization']);
        self::assertSame('typed-concave-target-spend-v1', $r['certificate']['external_numerical_certificate']['version']);
        self::assertLessThanOrEqual((float) $r['scenarios']['optimized']['check']['spend_exact'], $r['certificate']['external_numerical_certificate']['lower_bound_approximate']);
        self::assertLessThan(1e-5, $r['certificate']['external_numerical_certificate']['gap_approximate']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
        $spec['target'] = '10';
        $failed = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
        self::assertSame('solver_not_accepted', $failed['status']);
    }

    public function test_nonlinear_cpa_and_roas_use_fractional_bound_not_full_budget_response_maximum(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        foreach (['MINIMIZE_CPA', 'MAXIMIZE_ROAS'] as $objective) {
            [$ledger,$spec,$models] = $this->fixtureProblem();
            $l = $ledger->jsonSerialize();
            $rows = [];
            foreach ($l['rows'] as $key => $row) {
                $rows[] = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $l['scope'], 'spend' => '5', 'conversions' => '1', 'conversion_value' => '1'];
            }
            $ledger = new RecordedLedger($l['scope'], $rows);
            $spec['source_snapshot_id'] = $ledger->jsonSerialize()['snapshot_id'];
            $spec['objective'] = $objective;
            $spec['response_metric'] = $objective === 'MINIMIZE_CPA' ? 'conversions' : 'conversion_value';
            $spec['minimum_spend'] = '2';
            $spec['minimum_response'] = '0.1';
            foreach ($models as &$model) {
                $model['objective'] = $objective;
                $model['source_snapshot_id'] = $spec['source_snapshot_id'];
                $model['curve'] = ResponseCurve::log(1., 1., 10.);
            }unset($model);
            $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
            self::assertEqualsWithDelta(2., (float) $r['scenarios']['optimized']['check']['spend_exact'], 1e-5);
            self::assertEqualsWithDelta(1., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-4);
            self::assertEqualsWithDelta($objective === 'MINIMIZE_CPA' ? 1 / log(2) : log(2), $r['scenarios']['optimized']['check']['objective_approximate'], 1e-6);
            self::assertSame('typed-concave-ratio-v1', $r['certificate']['external_numerical_certificate']['version']);
            self::assertGreaterThanOrEqual(log(2) - 1e-7, $r['certificate']['external_numerical_certificate']['ratio_upper_approximate']);
            self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
        }
    }

    public function test_binding_nonlinear_cpa_and_roas_targets_maximize_response_with_necessary_tangent_certificate(): void
    {
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        foreach (['TARGET_CPA', 'TARGET_ROAS'] as $objective) {
            [$ledger,$spec,$models] = $this->fixtureProblem();
            $l = $ledger->jsonSerialize();
            $rows = [];
            foreach ($l['rows'] as $key => $row) {
                $rows[] = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $l['scope'], 'spend' => '5', 'conversions' => '1', 'conversion_value' => '1'];
            }
            $ledger = new RecordedLedger($l['scope'], $rows);
            $spec['source_snapshot_id'] = $ledger->jsonSerialize()['snapshot_id'];
            $spec['objective'] = $objective;
            $spec['target'] = $objective === 'TARGET_CPA' ? '2' : '0.5';
            $spec['target_optimization'] = 'maximize_response';
            $spec['response_metric'] = $objective === 'TARGET_CPA' ? 'conversions' : 'conversion_value';
            $spec['minimum_spend'] = '2';
            $spec['minimum_response'] = '0.1';
            foreach ($models as &$model) {
                $model['objective'] = $objective;
                $model['source_snapshot_id'] = $spec['source_snapshot_id'];
                $model['curve'] = ResponseCurve::log(1., 1., 10.);
            }unset($model);
            $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
            $spent = (float) $r['scenarios']['optimized']['check']['spend_exact'];
            // Independent root bracket for log(1+x)=x/2, not a copy of solver result.
            $lo = 1.;
            $hi = 5.;
            for ($i = 0; $i < 80; $i++) {
                $mid = ($lo + $hi) / 2;
                if (log1p($mid) > $mid / 2) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            self::assertEqualsWithDelta($lo + $hi, $spent, 1e-5);
            self::assertEqualsWithDelta($spent / 2., $r['scenarios']['optimized']['check']['response_approximate'], 1e-6);
            self::assertLessThan(10., $spent);
            $cert = $r['certificate']['external_numerical_certificate'];
            self::assertSame('typed-concave-ratio-target-v1', $cert['version']);
            self::assertGreaterThanOrEqual($r['scenarios']['optimized']['check']['response_approximate'] - 1e-9, $cert['objective_bound_approximate']);
            self::assertLessThan(1e-5, $cert['gap_approximate']);
            self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
            $spec['target_optimization'] = 'minimize_spend';
            $minimum = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $minimum['status'], json_encode($minimum['solver_result'] ?? []));
            self::assertEqualsWithDelta(2., (float) $minimum['scenarios']['optimized']['check']['spend_exact'], 1e-6);
            self::assertSame('lower', $minimum['certificate']['external_numerical_certificate']['bound_direction']);
            self::assertLessThanOrEqual((float) $minimum['scenarios']['optimized']['check']['spend_exact'], $minimum['certificate']['external_numerical_certificate']['objective_bound_approximate']);
            self::assertTrue((new DecisionEvidenceReplay)->replay($minimum)['matches_certificate']);
            $spec['minimum_response'] = '2';
            $floorBound = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $floorBound['status'], json_encode($floorBound['solver_result'] ?? []));
            self::assertEqualsWithDelta(2 * (exp(1) - 1), (float) $floorBound['scenarios']['optimized']['check']['spend_exact'], 1e-5);
            self::assertLessThanOrEqual((float) $floorBound['scenarios']['optimized']['check']['spend_exact'], $floorBound['certificate']['external_numerical_certificate']['objective_bound_approximate']);
            $spec['target'] = $objective === 'TARGET_CPA' ? '0.5' : '2';
            $bad = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($spec), $models);
            self::assertSame('solver_not_accepted', $bad['status']);
        }
    }

    public function test_dual_residual_box_correction_bounds_known_linear_optimum_without_stationarity_assumption(): void
    {
        [$ledger,$spec,$models] = $this->fixtureProblem();
        $spec['allocation_mode'] = 'continuous_linear';
        $problem = new DecisionProblem($spec);
        // Deliberately weak/sign-wrong proposed duals. Projection and exact box correction must still bound true maximum20.
        $step = ['dual_inequality' => [.1, .2, .3], 'dual_lower' => [-.1, -.2], 'dual_upper' => [.3, .4]];
        $bound = (new LinearDualResidualBound)->maximum($problem, [2., 1.], $step);
        self::assertSame('bounded', $bound['status']);
        self::assertGreaterThanOrEqual(20., $bound['upper_approximate']);
        $step = ['dual_inequality' => [-2., 0., 0.], 'dual_lower' => [0., 0.], 'dual_upper' => [0., 0.]];
        $bound = (new LinearDualResidualBound)->maximum($problem, [2., 1.], $step);
        self::assertSame(20., $bound['upper_approximate']);
    }
}
