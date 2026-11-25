<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceArchive;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\FiniteMenuReferenceSolver;
use App\Services\Decision\FrozenBudgetSensitivity;
use App\Services\Decision\IndependentAllocationCheck;
use App\Services\Decision\IntegratedDecisionPipeline;
use App\Services\Decision\LedgerModelBuilder;
use App\Services\Decision\TypedFiniteMilpSolver;
use App\Services\Decision\TypedLinearLpSolver;
use Brick\Math\BigRational;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IntegratedLedgerPipelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_exact_daily_source_fit_joint_constraints_scenarios_and_owner_scoped_archive(): void
    {
        $scope = ['provider' => 'synthetic', 'account_id' => 'synthetic-account', 'source_contract' => 'synthetic-calendar-v1', 'currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-10-01/2026-10-30', 'outcome_definition' => 'synthetic-purchase-credit', 'outcome_kind' => 'purchase',
            'attribution' => 'synthetic-1d', 'population' => 'a+b', 'conversion_count_kind' => 'integral', 'provenance_kind' => 'synthetic_fixture'];
        $rows = [];
        for ($d = 0; $d < 30; $d++) {
            $date = (new \DateTimeImmutable('2026-10-01'))->modify('+'.$d.' days')->format('Y-m-d');
            foreach (['a' => 2, 'b' => 1] as $id => $slope) {
                $spend = 10 + $d % 10;
                $rows[] = ['key' => $id.':'.$date, 'variable_id' => $id, 'reporting_date' => $date, 'source_ref' => 'synthetic:'.$id.':'.$date, 'scope' => $scope,
                    'spend' => (string) $spend, 'impressions' => '1000', 'clicks' => '100', 'conversions' => (string) ($spend * $slope), 'conversion_value' => (string) ($spend * $slope * 3)];
            }
        }
        $ledger = RecordedLedger::fromRecordedJson($scope, json_encode($rows, JSON_THROW_ON_ERROR));
        $l = $ledger->jsonSerialize();
        $spec = array_intersect_key($scope, array_flip(['currency', 'outcome_definition', 'reporting_window', 'timezone', 'population', 'outcome_kind'])) +
            ['source_snapshot_id' => $l['snapshot_id'], 'objective' => 'MAXIMIZE_CONVERSIONS', 'response_metric' => 'conversions', 'response_unit' => 'synthetic-credit',
                'business_estimand' => 'synthetic-test-only', 'budget_semantics' => 'period_total_not_daily_rate', 'horizon_days' => 30, 'forecast_horizon_days' => 3, 'budget_cap' => '900',
                'minimum_spend' => '0', 'minimum_response' => '0', 'allocation_mode' => 'finite_menu', 'interaction_model' => 'separable_no_overlap_claim',
                'groups' => [['id' => 'platform', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => '900']],
                'variables' => []];
        foreach (['a', 'b'] as $id) {
            $spec['variables'][] = ['id' => $id, 'platform' => 'synthetic', 'campaign_type' => 'purchase', 'entity_level' => 'campaign', 'entity_id' => $id,
                'current_spend' => $l['entity_totals'][$id]['spend'], 'minimum' => '300', 'maximum' => '570', 'menu' => ['300', '435', '465', '570']];
        }
        $problem = new DecisionProblem($spec);
        $engine = new IntegratedDecisionPipeline;
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);
        $built = (new LedgerModelBuilder)->build($ledger, $problem);
        $milp = (new TypedFiniteMilpSolver)->solve($problem, $built['models']);
        self::assertSame('checked_numerical_solver', $milp['status'], json_encode($milp));
        self::assertSame(['a' => '570', 'b' => '300'], $milp['allocation']);
        foreach (DecisionProblem::OBJECTIVES as $objective) {
            if ($objective === 'MAXIMIZE_LEADS') {
                continue;
            } // Lead semantics need a genuinely lead-defined source, covered by contract tests.
            $otherSpec = $spec;
            $otherSpec['objective'] = $objective;
            $otherSpec['response_metric'] = in_array($objective, ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'TARGET_CPA'], true) ? 'conversions' : 'conversion_value';
            $otherSpec['minimum_spend'] = '600';
            $otherSpec['minimum_response'] = '1';
            if (str_starts_with($objective, 'TARGET_')) {
                $otherSpec['target_optimization'] = 'minimize_spend';
                $otherSpec['target'] = match ($objective) {
                    'TARGET_REVENUE' => '3500','TARGET_CPA' => '0.8',default => '4'
                };
            }
            $otherProblem = new DecisionProblem($otherSpec);
            $otherModels = (new LedgerModelBuilder)->build($ledger, $otherProblem)['models'];
            $m = (new TypedFiniteMilpSolver)->solve($otherProblem, $otherModels);
            self::assertSame('checked_numerical_solver', $m['status'], $objective.':'.json_encode($m));
            $reference = (new FiniteMenuReferenceSolver)->solve($otherProblem, $otherModels);
            $refCheck = (new IndependentAllocationCheck)->check($otherProblem, $otherModels, $reference['allocation']);
            if (str_starts_with($objective, 'TARGET_')) {
                self::assertSame($refCheck['spend_exact'], $m['independent_check']['spend_exact']);
            } else {
                self::assertEqualsWithDelta($refCheck['objective_approximate'], $m['independent_check']['objective_approximate'], 1e-7);
            }
        }

        $lpSpec = $spec;
        $lpSpec['allocation_mode'] = 'continuous_linear';
        foreach ($lpSpec['variables'] as &$v) {
            $v['menu'] = [];
        }unset($v);
        $lp = (new IntegratedDecisionPipeline)->fromLedger($ledger, new DecisionProblem($lpSpec));
        self::assertSame('typed_linear_lp', $lp['route']['solver']);
        self::assertSame('checked_reference_scenario', $lp['status'], json_encode($lp['solver_result'] ?? []));
        self::assertSame('900', $lp['scenarios']['optimized']['check']['spend_exact']);
        $linearBound = $lp['certificate']['external_numerical_certificate']['represented_linear_optimality_bound'];
        self::assertSame('bounded', $linearBound['status']);
        $boundValue = BigRational::of($linearBound['upper_exact']['numerator'])->dividedBy($linearBound['upper_exact']['denominator']);
        $candidateValue = BigRational::of($linearBound['candidate_response_exact']['numerator'])->dividedBy($linearBound['candidate_response_exact']['denominator']);
        self::assertTrue($boundValue->isGreaterThanOrEqualTo($candidateValue));
        self::assertTrue($boundValue->minus($candidateValue)->isLessThan('0.0001'));

        self::assertEqualsWithDelta(1470., $lp['scenarios']['optimized']['check']['response_approximate'], 1e-8);
        $withShocks = $lpSpec;
        $withShocks['budget_shock_multipliers'] = ['0.9', '1.1'];
        $withShocks['primitive_shocks'] = [['cvr' => '0.9'], ['cpm' => '1.15'], ['budget' => '1.1']];
        $shockResult = $engine->fromLedger($ledger, new DecisionProblem($withShocks));
        self::assertSame('evaluated_budget_shocks', $shockResult['sensitivity']['status']);
        self::assertSame('evaluated_ledger_primitive_identities', $shockResult['primitive_scenarios']['status']);
        self::assertFalse($shockResult['primitive_scenarios']['reoptimized']);
        self::assertSame(3, count($shockResult['consumers']['analyst_input']['optimization']['primitive_scenarios']['scenarios']));
        self::assertSame('evaluated_budget_shocks', $shockResult['consumers']['charts']['sensitivity_chart']['status']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($shockResult)['matches_consumers']);
        $sensitivity = (new FrozenBudgetSensitivity)->analyze($lp, ['0.9', '1.1']);
        self::assertSame('evaluated_budget_shocks', $sensitivity['status']);
        self::assertSame('rejected', $sensitivity['scenarios'][0]['fixed_allocation_check']['status']);
        self::assertSame('810.0', $sensitivity['scenarios'][0]['budget_cap_exact']);
        self::assertNull($sensitivity['stability_probability']);
        self::assertSame('570', $lp['scenarios']['optimized']['allocation']['a']);
        self::assertSame('330', $lp['scenarios']['optimized']['allocation']['b']);
        foreach (DecisionProblem::OBJECTIVES as $objective) {
            if ($objective === 'MAXIMIZE_LEADS') {
                continue;
            }
            $s = $lpSpec;
            $s['objective'] = $objective;
            $s['response_metric'] = in_array($objective, ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'TARGET_CPA'], true) ? 'conversions' : 'conversion_value';
            $s['minimum_spend'] = '600';
            $s['minimum_response'] = '1';
            if (str_starts_with($objective, 'TARGET_')) {
                $s['target_optimization'] = 'minimize_spend';
                $s['target'] = match ($objective) {
                    'TARGET_REVENUE' => '3510','TARGET_CPA' => '0.8',default => '4'
                };
            }
            $problemLp = new DecisionProblem($s);
            $modelsLp = (new LedgerModelBuilder)->build($ledger, $problemLp)['models'];
            $r = (new TypedLinearLpSolver)->solve($problemLp, $modelsLp);
            self::assertSame('checked_numerical_solver', $r['status'], $objective.':'.json_encode($r));
            self::assertSame('checked', $r['independent_check']['status']);
            if (str_starts_with($objective, 'TARGET_')) {
                $s['target_optimization'] = 'maximize_response';
                $maxProblem = new DecisionProblem($s);
                $maxModels = (new LedgerModelBuilder)->build($ledger, $maxProblem)['models'];
                $max = (new TypedLinearLpSolver)->solve($maxProblem, $maxModels);
                self::assertSame('checked_numerical_solver', $max['status'], json_encode($max));
                self::assertSame('900', $max['independent_check']['spend_exact']);
                $menuSpec = $s;
                $menuSpec['allocation_mode'] = 'finite_menu';
                foreach ($menuSpec['variables'] as &$v) {
                    $v['menu'] = ['300', '330', '435', '465', '570'];
                }unset($v);
                $menuProblem = new DecisionProblem($menuSpec);
                $menuModels = (new LedgerModelBuilder)->build($ledger, $menuProblem)['models'];
                $milpMax = (new TypedFiniteMilpSolver)->solve($menuProblem, $menuModels);
                $ref = (new FiniteMenuReferenceSolver)->solve($menuProblem, $menuModels);
                self::assertSame('checked_numerical_solver', $milpMax['status'], json_encode($milpMax));
                $refCheck = (new IndependentAllocationCheck)->check($menuProblem, $menuModels, $ref['allocation']);
                self::assertEqualsWithDelta($refCheck['response_approximate'], $milpMax['independent_check']['response_approximate'], 1e-8);
            }
        }
        $groupSpec = $lpSpec;
        $groupSpec['groups'][] = ['id' => 'atomic-a', 'members' => ['a'], 'minimum' => '300', 'maximum' => '450'];
        $groupResult = $engine->fromLedger($ledger, new DecisionProblem($groupSpec));
        self::assertSame('450', $groupResult['scenarios']['optimized']['allocation']['a']);
        self::assertSame('450', $groupResult['scenarios']['optimized']['allocation']['b']);
        self::assertEqualsWithDelta(1350., $groupResult['scenarios']['optimized']['check']['response_approximate'], 1e-8);
        $riskSpec = $lpSpec;
        $riskSpec['risk'] = ['mode' => 'independent_response_scale_box', 'evidence_id' => 'synthetic-test', 'bounds' => ['a' => ['lower_scale' => '0.25', 'upper_scale' => '1'], 'b' => ['lower_scale' => '1', 'upper_scale' => '1']]];
        $riskResult = $engine->fromLedger($ledger, new DecisionProblem($riskSpec));
        self::assertSame('330', $riskResult['scenarios']['optimized']['allocation']['a']);
        self::assertSame('570', $riskResult['scenarios']['optimized']['allocation']['b']);
        self::assertEqualsWithDelta(735., $riskResult['scenarios']['optimized']['check']['response_approximate'], 1e-8);
        self::assertNull($riskResult['scenarios']['optimized']['check']['stress']['probability']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($lp)['matches_consumers']);
        $nonlinear = $lpSpec;
        $lpModels = (new LedgerModelBuilder)->build($ledger, new DecisionProblem($nonlinear))['models'];
        $lpModels['a']['curve'] = ResponseCurve::log(2., .01, 570., 300.);
        $lpModels['a']['response_shape'] = 'log';
        $rejected = $engine->run($ledger, new DecisionProblem($nonlinear), $lpModels);
        self::assertSame('abstained', $rejected['status']);
        self::assertContains('continuous_linear_requires_linear_response:a', $rejected['route']['reasons']);
        $infeasible = $lpSpec;
        $infeasible['groups'][] = ['id' => 'too-small', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => '599'];
        self::assertSame('solver_not_accepted', $engine->fromLedger($ledger, new DecisionProblem($infeasible))['status']);
        $decimalCap = $lpSpec;
        $decimalCap['budget_cap'] = '870.1';
        $decimalResult = $engine->fromLedger($ledger, new DecisionProblem($decimalCap));
        self::assertSame('checked_reference_scenario', $decimalResult['status'], json_encode($decimalResult['solver_result'] ?? []));
        self::assertSame('870.1', $decimalResult['scenarios']['optimized']['check']['spend_exact']);
        self::assertSame('recovered_candidate', $decimalResult['certificate']['external_numerical_certificate']['exact_vertex_recovery']['status']);

        $largeSpec = $spec;
        foreach ($largeSpec['variables'] as &$v) {
            $v['menu'] = array_map(fn ($n) => number_format($n / 2, 1, '.', ''), range(600, 1140));
        }unset($v);
        $large = $engine->fromLedger($ledger, new DecisionProblem($largeSpec));
        self::assertSame('typed_finite_milp', $large['route']['solver']);
        self::assertSame('checked_reference_scenario', $large['status']);
        self::assertSame(['a' => '570.0', 'b' => '330.0'], $large['scenarios']['optimized']['allocation']);
        self::assertSame('900.0', $large['scenarios']['optimized']['check']['spend_exact']);
        self::assertNull($large['certificate']['examined_menu_combinations']);
        $result = $engine->fromLedger($ledger, $problem, ['a' => '300', 'b' => '570']);
        self::assertSame('built', $result['model_build']['status']);
        self::assertSame('computed_read_only', $result['consumers']['status']);
        self::assertCount(15, $result['consumers']['charts']);
        self::assertSame('available_data', $result['consumers']['charts']['cpa_trend']['status']);
        self::assertSame('Insufficient Data', $result['consumers']['diagnostics']['a']['health']);
        self::assertSame('evaluated_uncalibrated_forecast', $result['consumers']['analyst_input']['forecast']['status']);
        self::assertFalse($result['consumers']['external_ai_called']);
        self::assertSame('no_change_modeled', $result['consumers']['analyst_input']['optimization']['scenarios'][0]['name']);
        self::assertSame($result['snapshot_id'], $result['consumers']['snapshot_id']);
        self::assertSame('evaluated_uncalibrated_forecast', $result['forecast']['status']);
        self::assertNull($result['forecast']['probability_of_success']);
        self::assertNull($result['forecast']['points'][0]['lower']);
        self::assertSame('checked_reference_scenario', $result['status']);
        self::assertSame(['a' => '570', 'b' => '300'], $result['scenarios']['optimized']['allocation']);
        self::assertEqualsWithDelta(1440, $result['scenarios']['optimized']['check']['response_approximate'], 1e-6);
        self::assertEqualsWithDelta(1305, $result['scenarios']['current']['check']['response_approximate'], 1e-6);
        self::assertEqualsWithDelta(1170, $result['scenarios']['custom']['check']['response_approximate'], 1e-6);
        self::assertSame('870', $result['actual']['totals']['spend']);
        $incidence = $result['consumers']['allocation_incidence'];
        self::assertSame('computed_atomic_membership', $incidence['status']);
        self::assertSame(['a', 'b'], $incidence['platform_membership']['synthetic']);
        self::assertSame('870', $incidence['scenarios']['optimized']['platform_spend_exact']['synthetic']);
        self::assertSame('870', $incidence['scenarios']['optimized']['platform_partition_sum_exact']);
        self::assertSame('870', $incidence['scenarios']['optimized']['constraint_group_spend']['platform']['spend_exact']);
        $comparison = $result['consumers']['analyst_input']['optimization']['comparison']['comparisons'];
        self::assertTrue($comparison['no_change_modeled']['same_total_spend']);
        self::assertSame(['numerator' => '0', 'denominator' => '1'], $comparison['no_change_modeled']['spend_change_fraction_exact']);
        self::assertEqualsWithDelta(135., $comparison['no_change_modeled']['response_change_approximate'], 1e-6);
        self::assertEqualsWithDelta(135. / 1305., $comparison['no_change_modeled']['response_change_fraction_approximate'], 1e-9);
        self::assertNull($comparison['no_change_modeled']['causal_lift']);
        self::assertSame('540', $comparison['custom']['allocation_l1_change_exact']);
        self::assertSame('0', $result['scenarios']['optimized']['check']['money_constraint_tolerance']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $archive = new DecisionEvidenceArchive;
        $id = $archive->save($owner->id, $result);
        self::assertSame($result['audit_hash'], $archive->load($owner->id, $id)['audit_hash']);
        self::assertNull($archive->load($other->id, $id));
        self::assertTrue((new DecisionEvidenceReplay)->replay($archive->load($owner->id, $id))['matches_scenarios']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($archive->load($owner->id, $id))['matches_consumers']);
        self::assertSame('available_data', $result['consumers']['charts']['response_curve']['status']);
        self::assertEqualsWithDelta(2.0, $result['consumers']['charts']['marginal_return']['curves']['a']['points'][0]['marginal_approximate'], 1e-8);
        self::assertSame($result['audit_hash'], $engine->fromLedger($ledger, $problem, ['a' => '300', 'b' => '570'])['audit_hash']);
        $bad = $result;
        $bad['actual']['totals']['spend'] = '0';
        DB::table('decision_evidence_runs')->where('id', $id)->update(['evidence_json' => json_encode($bad)]);
        $this->expectException(\InvalidArgumentException::class);
        $archive->load($owner->id, $id);
    }
}
