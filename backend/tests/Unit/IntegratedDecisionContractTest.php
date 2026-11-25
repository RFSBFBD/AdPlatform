<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Analytics\Curves\ResponseCurveContract;
use App\Services\Decision\Accounting\Binary64Boundary;
use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedJson;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\ExactAlgebraicModelCheck;
use App\Services\Decision\FiniteMenuReferenceSolver;
use App\Services\Decision\IndependentAllocationCheck;
use App\Services\Decision\IntegratedDecisionPipeline;
use PHPUnit\Framework\TestCase;

final class IntegratedDecisionContractTest extends TestCase
{
    private function ledger(string $kind = 'synthetic_fixture'): RecordedLedger
    {
        $scope = ['provider' => 'synthetic', 'account_id' => 'account', 'source_contract' => 'synthetic-v1', 'currency' => 'EGP',
            'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-10-01/2026-10-30', 'outcome_definition' => 'purchase-credit', 'outcome_kind' => 'purchase',
            'attribution' => 'synthetic-1d', 'population' => 'atomic-a+b', 'conversion_count_kind' => 'integral', 'provenance_kind' => $kind];

        return new RecordedLedger($scope, [
            ['key' => 'a', 'variable_id' => 'a', 'source_ref' => 'fixture:a', 'scope' => $scope, 'spend' => '0.100001', 'impressions' => '9007199254740993', 'clicks' => '3', 'conversions' => '1', 'conversion_value' => '1.0000001'],
            ['key' => 'b', 'variable_id' => 'b', 'source_ref' => 'fixture:b', 'scope' => $scope, 'spend' => '0.200002', 'impressions' => '9', 'clicks' => '0', 'conversions' => '0', 'conversion_value' => '0.0000002'],
        ]);
    }

    private function spec(RecordedLedger $ledger): array
    {
        $l = $ledger->jsonSerialize();
        $spec = array_intersect_key($l['scope'], array_flip(['currency', 'outcome_definition', 'reporting_window', 'timezone', 'population', 'outcome_kind']));

        return $spec + ['source_snapshot_id' => $l['snapshot_id'], 'objective' => 'MAXIMIZE_CONVERSIONS', 'response_metric' => 'conversions',
            'response_unit' => 'credited_purchase', 'budget_semantics' => 'period_total_not_daily_rate', 'business_estimand' => 'synthetic reported-credit surrogate', 'horizon_days' => 30,
            'budget_cap' => '10', 'minimum_spend' => '0', 'minimum_response' => '0', 'allocation_mode' => 'finite_menu',
            'interaction_model' => 'separable_no_overlap_claim', 'groups' => [['id' => 'platform-cap', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => '10']],
            'variables' => [
                ['id' => 'a', 'platform' => 'synthetic', 'campaign_type' => 'conversion', 'entity_level' => 'campaign', 'entity_id' => 'a', 'current_spend' => $l['entity_totals']['a']['spend'], 'minimum' => '0', 'maximum' => '10', 'menu' => ['0', '5', '10']],
                ['id' => 'b', 'platform' => 'synthetic', 'campaign_type' => 'conversion', 'entity_level' => 'campaign', 'entity_id' => 'b', 'current_spend' => $l['entity_totals']['b']['spend'], 'minimum' => '0', 'maximum' => '10', 'menu' => ['0', '5', '10']],
            ]];
    }

    private function models(RecordedLedger $ledger): array
    {
        $models = [];
        foreach (['a' => 2.0, 'b' => 1.0] as $id => $slope) {
            $models[$id] = ['curve' => ResponseCurve::linear($slope, 10), 'source_snapshot_id' => $ledger->jsonSerialize()['snapshot_id'],
                'objective' => 'MAXIMIZE_CONVERSIONS', 'platform' => 'synthetic', 'campaign_type' => 'conversion', 'period_days' => 30,
                'data_volume' => 30, 'data_quality' => ['hard_gates' => 'passed', 'policy_version' => 'synthetic-test'], 'variance' => '1', 'history_days' => 30,
                'response_shape' => 'linear', 'model_version' => 'synthetic-v1', 'source_admission' => 'synthetic_fixture',
                'validation' => ['status' => 'passed', 'method' => 'known-synthetic-law-not-empirical', 'evidence_id' => 'fixture:linear']];
        }

        return $models;
    }

    public function test_exact_represented_linear_menu_ranking_does_not_treat_distinct_decimal_budgets_as_binary64_ties(): void
    {
        $ledger = $this->ledger();
        $spec = $this->spec($ledger);
        $spec['variables'][0]['menu'] = ['1', '1.00000000000000001'];
        $spec['variables'][1]['menu'] = ['0'];
        $models = $this->models($ledger);
        $r = (new FiniteMenuReferenceSolver)->solve(new DecisionProblem($spec), $models);
        self::assertSame('1.00000000000000001', $r['allocation']['a']);
        self::assertSame('exact_rational_represented_linear_model', $r['ranking_arithmetic']);
        self::assertSame(2, $r['feasible']);
        $spec['objective'] = 'MINIMIZE_CPA';
        $spec['minimum_spend'] = '1';
        $spec['minimum_response'] = '1';
        $spec['variables'][0]['menu'] = ['1'];
        $spec['variables'][1]['menu'] = ['0', '0.00000000000000001'];
        $r = (new FiniteMenuReferenceSolver)->solve(new DecisionProblem($spec), $models);
        self::assertSame('0', $r['allocation']['b']);
        $models['b']['curve'] = ResponseCurve::linear(3., 10.);
        $r = (new FiniteMenuReferenceSolver)->solve(new DecisionProblem($spec), $models);
        self::assertSame('0.00000000000000001', $r['allocation']['b']);
    }

    public function test_algebraic_non_linear_target_does_not_pass_when_binary64_rounds_response_up_to_target(): void
    {
        $ledger = $this->ledger();
        $spec = $this->spec($ledger);
        $spec['objective'] = 'TARGET_REVENUE';
        $spec['response_metric'] = 'conversion_value';
        $spec['target'] = '0.75';
        $spec['target_optimization'] = 'maximize_response';
        $spec['variables'][0]['menu'] = ['0.99999999999999999', '1'];
        $models = $this->models($ledger);
        $models['a']['curve'] = ResponseCurve::quadraticSpline(1., .5, 1., 10.);
        $models['b']['curve'] = ResponseCurve::piecewise(1., .5, 1., 10.);
        $p = new DecisionProblem($spec);
        $bad = (new IndependentAllocationCheck)->check($p, $models, ['a' => '0.99999999999999999', 'b' => '0']);
        self::assertSame(.75, $bad['response_approximate']);
        self::assertSame('rejected', $bad['status']);
        self::assertContains('represented_algebraic_target_not_met', $bad['represented_algebraic_check']['reasons']);
        $good = (new IndependentAllocationCheck)->check($p, $models, ['a' => '1', 'b' => '0']);
        self::assertSame('checked', $good['status']);
        self::assertSame(['numerator' => '3', 'denominator' => '4'], $good['represented_algebraic_check']['response']);
        $tail = (new ExactAlgebraicModelCheck)->check($p, $models, ['a' => '2', 'b' => '2']);
        self::assertSame(['numerator' => '11', 'denominator' => '4'], $tail['response']);
    }

    public function test_finite_reference_rejects_negative_individual_response_even_if_portfolio_sum_is_positive(): void
    {
        $ledger = $this->ledger();
        $spec = $this->spec($ledger);
        $spec['variables'][1]['menu'] = ['5'];
        $models = $this->models($ledger);
        $curve = $this->createStub(ResponseCurveContract::class);
        $curve->method('supportMaxSpend')->willReturn(10.);
        $curve->method('revenueAt')->willReturnCallback(fn ($x) => -$x);
        $models['a']['curve'] = $curve;
        $problem = new DecisionProblem($spec);
        $solved = (new FiniteMenuReferenceSolver)->solve($problem, $models);
        self::assertSame(['a' => '0', 'b' => '5'], $solved['allocation']);
        self::assertSame(1, $solved['feasible']);
        self::assertSame('checked', (new IndependentAllocationCheck)->check($problem, $models, $solved['allocation'])['status']);
    }

    public function test_all_linear_scenario_checks_enforce_represented_target_not_rounded_binary_comparison(): void
    {
        $ledger = $this->ledger();
        $spec = $this->spec($ledger);
        $spec['objective'] = 'TARGET_REVENUE';
        $spec['response_metric'] = 'conversion_value';
        $spec['target'] = '1.00000000000000000001';
        $spec['target_optimization'] = 'minimize_spend';
        $spec['variables'][0]['menu'] = ['0', '1'];
        $spec['variables'][1]['menu'] = ['0'];
        $models = $this->models($ledger);
        $models['a']['curve'] = ResponseCurve::linear(1., 10.);
        $r = (new IndependentAllocationCheck)->check(new DecisionProblem($spec), $models, ['a' => '1', 'b' => '0']);
        self::assertSame('rejected', $r['status']);
        self::assertContains('represented_linear_feasibility_rejected', $r['reasons']);
        self::assertContains('represented_linear_target_not_met', $r['represented_linear_check']['reasons']);
        self::assertSame(['numerator' => '1', 'denominator' => '1'], $r['represented_linear_check']['response']);
        $spec['variables'][0]['menu'] = ['0', '1', '2'];
        $solved = (new FiniteMenuReferenceSolver)->solve(new DecisionProblem($spec), $models);
        self::assertSame(['a' => '2', 'b' => '0'], $solved['allocation']);
    }

    public function test_malformed_lists_scope_and_nested_members_reject_as_contract_errors(): void
    {
        $base = $this->spec($this->ledger());
        $mutations = [
            fn ($s) => array_replace($s, ['currency' => 'egp']),
            fn ($s) => array_replace($s, ['timezone' => 'invalid-zone']),
            fn ($s) => array_replace($s, ['reporting_window' => '2026-02-30/2026-03-01']),
            fn ($s) => array_replace($s, ['reporting_window' => '2026-11-02/2026-11-01']),
            fn ($s) => array_replace($s, ['variables' => ['named' => $s['variables'][0]]]),
            fn ($s) => array_replace($s, ['groups' => ['named' => $s['groups'][0]]]),
        ];
        foreach ([['nested'], null, 1, new \stdClass] as $member) {
            $s = $base;
            $s['groups'][0]['members'] = ['a', $member];
            $mutations[] = fn ($unused) => $s;
        }
        $s = $base;
        $s['variables'][0]['menu'] = ['named' => '5'];
        $mutations[] = fn ($unused) => $s;
        foreach ($mutations as $mutate) {
            try {
                new DecisionProblem($mutate($base));
                self::fail('malformed problem accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_atomic_identity_tuple_is_not_ambiguous_when_identifiers_contain_delimiters(): void
    {
        $spec = $this->spec($this->ledger());
        $spec['variables'][0]['platform'] = 'provider|campaign';
        $spec['variables'][0]['entity_id'] = 'x';
        $spec['variables'][1]['platform'] = 'provider';
        $spec['variables'][1]['entity_id'] = 'campaign|x';
        $problem = new DecisionProblem($spec);
        self::assertCount(2, $problem->jsonSerialize()['variables']);
    }

    public function test_exact_tokens_and_rational_totals_exceed_legacy_precision(): void
    {
        $l = $this->ledger()->jsonSerialize();
        self::assertSame('0.300003', $l['totals']['spend']);
        self::assertSame('9007199254741002', $l['totals']['impressions']);
        self::assertSame('1.0000003', $l['totals']['conversion_value']);
        self::assertSame(['numerator' => '1', 'denominator' => '3'], $l['ratios']['cvr']['exact']);
        self::assertSame('0.100001', $l['rows']['a']['metrics']['spend']['lexeme']);
        self::assertSame(4, ExactValue::of('1.0000')->jsonSerialize()['source_scale']);
        self::assertSame('unverified_by_arithmetic', $l['source_truth']);
    }

    public function test_recorded_json_preserves_original_decimal_and_large_integer_tokens(): void
    {
        self::assertSame(['v' => '0.100001', 'i' => '9007199254740993', 's' => 'text 123', 'nested' => ['1e-5']],
            RecordedJson::decode('{"v":0.100001,"i":9007199254740993,"s":"text 123","nested":[1e-5]}'));
    }

    public function test_float_and_unsupported_lexemes_and_fractional_integral_counts_reject(): void
    {
        foreach ([0.1, 1.0, '1e-5', ' 1', 'NaN', '01', '-1', '1.1'] as $bad) {
            try {
                ExactValue::of($bad, true);
                self::fail('accepted unsupported operand');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_snapshot_order_invariance_and_duplicate_rejection(): void
    {
        $l = $this->ledger()->jsonSerialize();
        $rows = [];
        foreach ($l['rows'] as $key => $row) {
            $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'scope' => $l['scope']];
            foreach ($row['metrics'] as $k => $v) {
                $r[$k] = $v['lexeme'];
            } $rows[] = $r;
        }
        self::assertSame($l['snapshot_id'], (new RecordedLedger($l['scope'], array_reverse($rows)))->jsonSerialize()['snapshot_id']);
        $this->expectException(\InvalidArgumentException::class);
        new RecordedLedger($l['scope'], [$rows[0], $rows[0]]);
    }

    public function test_positive_reference_pipeline_and_snapshot_replay(): void
    {
        $l = $this->ledger();
        $p = new DecisionProblem($this->spec($l));
        $m = $this->models($l);
        $engine = new IntegratedDecisionPipeline;
        $r = $engine->run($l, $p, $m, ['a' => '0', 'b' => '10']);
        self::assertSame('checked_reference_scenario', $r['status']);
        self::assertSame(['a' => '10', 'b' => '0'], $r['scenarios']['optimized']['allocation']);
        self::assertSame(20.0, $r['scenarios']['optimized']['check']['response_approximate']);
        self::assertEqualsWithDelta(0.400004, $r['scenarios']['current']['check']['response_approximate'], 1e-12);
        self::assertSame(10.0, $r['scenarios']['custom']['check']['response_approximate']);
        self::assertSame(9, $r['certificate']['examined_menu_combinations']);
        self::assertSame($r['audit_hash'], $engine->run($l, $p, $m, ['a' => '0', 'b' => '10'])['audit_hash']);
        self::assertFalse($r['operational_approval']);
        self::assertNull($r['probability_of_success']);
    }

    public function test_exact_group_cap_changes_joint_optimum_without_double_counting(): void
    {
        $l = $this->ledger();
        $s = $this->spec($l);
        $s['groups'][] = ['id' => 'a-cap', 'members' => ['a'], 'minimum' => '0', 'maximum' => '5'];
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($s), $this->models($l));
        self::assertSame(['a' => '5', 'b' => '5'], $r['scenarios']['optimized']['allocation']);
        self::assertSame('10', $r['scenarios']['optimized']['check']['spend_exact']);
    }

    public function test_exact_constraint_check_does_not_use_solver_epsilon(): void
    {
        $l = $this->ledger();
        $s = $this->spec($l);
        $s['budget_cap'] = '9.999999999999999999999';
        $r = (new IndependentAllocationCheck)->check(new DecisionProblem($s), $this->models($l), ['a' => '5', 'b' => '5']);
        self::assertSame('rejected', $r['status']);
        self::assertContains('exact_budget_bound', $r['reasons']);
    }

    public function test_source_and_validation_blocks_cannot_be_lifted_by_exact_arithmetic(): void
    {
        $l = $this->ledger('recorded_source');
        $m = $this->models($l);
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($this->spec($l)), $m);
        self::assertSame('abstained', $r['status']);
        self::assertContains('recorded_source_bridge_not_yet_qualified', $r['route']['reasons']);
        $l = $this->ledger();
        $m = $this->models($l);
        $m['a']['validation']['status'] = 'failed';
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($this->spec($l)), $m);
        self::assertSame('abstained', $r['status']);
        self::assertSame([], $r['scenarios']);
    }

    public function test_all_original_objectives_keep_their_semantics_on_known_menu(): void
    {
        foreach (DecisionProblem::OBJECTIVES as $objective) {
            $l = $this->ledger();
            $spec = $this->spec($l);
            if ($objective === 'MAXIMIZE_LEADS') {
                $original = $l->jsonSerialize();
                $scope = $original['scope'];
                $scope['outcome_kind'] = 'lead';
                $rows = [];
                foreach ($original['rows'] as $key => $row) {
                    $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'scope' => $scope];
                    foreach ($row['metrics'] as $k => $v) {
                        $r[$k] = $v['lexeme'];
                    }$rows[] = $r;
                }
                $l = new RecordedLedger($scope, $rows);
                $spec = $this->spec($l);
            }
            $spec['objective'] = $objective;
            $count = in_array($objective, ['MAXIMIZE_CONVERSIONS', 'MINIMIZE_CPA', 'MAXIMIZE_LEADS', 'TARGET_CPA'], true);
            $spec['response_metric'] = $count ? 'conversions' : 'conversion_value';
            $spec['minimum_spend'] = '5';
            $spec['minimum_response'] = '1';
            if ($objective === 'MAXIMIZE_LEADS') {
                $spec['outcome_kind'] = 'lead';
            }
            if (str_starts_with($objective, 'TARGET_')) {
                $spec['target_optimization'] = 'minimize_spend';
                $spec['target'] = match ($objective) {
                    'TARGET_CPA' => '0.6','TARGET_ROAS' => '1.5',default => '9'
                };
            }
            $models = $this->models($l);
            foreach ($models as &$m) {
                $m['objective'] = $objective;
            } unset($m);
            $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($spec), $models);
            self::assertSame('checked_reference_scenario', $r['status'], $objective);
            $expected = in_array($objective, ['MAXIMIZE_CONVERSIONS', 'MAXIMIZE_REVENUE', 'MAXIMIZE_LEADS'], true)
                ? ['a' => '10', 'b' => '0'] : ['a' => '5', 'b' => '0'];
            self::assertSame($expected, $r['scenarios']['optimized']['allocation'], $objective);
            if (str_starts_with($objective, 'TARGET_')) {
                $spec['target_optimization'] = 'maximize_response';
                $max = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($spec), $models);
                self::assertSame(['a' => '10', 'b' => '0'], $max['scenarios']['optimized']['allocation']);
                unset($spec['target_optimization']);
                try {
                    new DecisionProblem($spec);
                    self::fail('missing target policy admitted');
                } catch (\InvalidArgumentException $e) {
                    self::assertSame('explicit_target_optimization_required', $e->getMessage());
                }
            }
        }
    }

    public function test_target_policy_is_not_accepted_for_unrelated_objectives(): void
    {
        foreach (['target', 'target_optimization'] as $key) {
            $s = $this->spec($this->ledger());
            $s[$key] = $key === 'target' ? '1' : 'maximize_response';
            try {
                new DecisionProblem($s);
                self::fail('irrelevant target field admitted');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('target_policy_on_non_target_objective', $e->getMessage());
            }
        }
    }

    public function test_unknown_constraints_are_not_silently_ignored(): void
    {
        $l = $this->ledger();
        $spec = $this->spec($l);
        $spec['maximum_active'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        new DecisionProblem($spec);
    }

    public function test_duplicate_decision_entity_cannot_double_count_budget_response(): void
    {
        $l = $this->ledger();
        $spec = $this->spec($l);
        $spec['variables'][1]['entity_id'] = 'a';
        $this->expectException(\InvalidArgumentException::class);
        new DecisionProblem($spec);
    }

    public function test_duplicate_json_keys_including_escaped_equivalents_reject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecordedJson::decode('{"value":1,"\\u0076alue":2}');
    }

    public function test_undefined_ratio_is_not_fabricated_zero(): void
    {
        $l = $this->ledger()->jsonSerialize();
        $scope = $l['scope'];
        $r = ['key' => 'zero', 'variable_id' => 'a', 'source_ref' => 'fixture:zero', 'scope' => $scope];
        foreach (RecordedLedger::METRICS as $metric) {
            $r[$metric] = '0';
        }
        $zero = (new RecordedLedger($scope, [$r]))->jsonSerialize();
        self::assertNull($zero['ratios']['cpa']['exact']);
        self::assertSame('undefined_zero_denominator', $zero['ratios']['cpa']['reason']);
    }

    public function test_explicit_independent_scale_box_changes_robust_allocation_not_probability(): void
    {
        $l = $this->ledger();
        $spec = $this->spec($l);
        $spec['risk'] = ['mode' => 'independent_response_scale_box', 'evidence_id' => 'synthetic:scale-set',
            'bounds' => ['a' => ['lower_scale' => '0.1', 'upper_scale' => '1.5'], 'b' => ['lower_scale' => '0.9', 'upper_scale' => '1.1']]];
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($spec), $this->models($l));
        self::assertSame('checked_reference_scenario', $r['status']);
        self::assertSame(['a' => '0', 'b' => '10'], $r['scenarios']['optimized']['allocation']);
        self::assertSame(9.0, $r['scenarios']['optimized']['check']['response_approximate']);
        self::assertSame(10.0, $r['scenarios']['optimized']['check']['stress']['nominal_response_approximate']);
        self::assertSame(11.0, $r['scenarios']['optimized']['check']['stress']['upper_assumption_response_approximate']);
        self::assertNull($r['scenarios']['optimized']['check']['stress']['probability']);
    }

    public function test_model_boundary_measures_actual_binary64_error_not_printed_decimal(): void
    {
        $loss = Binary64Boundary::loss('0.1', 0.1);
        self::assertSame(['numerator' => '1', 'denominator' => '180143985094819840'], ExactValue::fraction($loss));
        self::assertSame(['numerator' => '3', 'denominator' => '2'], ExactValue::fraction(Binary64Boundary::rational(1.5)));
    }

    public function test_independent_python_decimal_fraction_reference_vectors(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/../Fixtures/exact-independent-reference.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['cases'] as $case) {
            $a = ExactValue::of($case['a']);
            $b = ExactValue::of($case['b']);
            self::assertSame($case['sum'], (string) $a->decimal()->plus($b->decimal()));
            self::assertSame($case['product'], (string) $a->decimal()->multipliedBy($b->decimal()));
            self::assertSame($case['ratio'], ExactValue::fraction($a->rational()->dividedBy($b->rational())));
        }
        foreach ($data['binary64'] as $case) {
            self::assertSame(['numerator' => $case['numerator'], 'denominator' => $case['denominator']],
                ExactValue::fraction(Binary64Boundary::rational((float) $case['float'])));
        }
    }

    public function test_unavailable_metric_stays_unknown_without_poisoning_other_exact_metrics(): void
    {
        $l = $this->ledger()->jsonSerialize();
        $scope = $l['scope'];
        $r = ['key' => 'a', 'variable_id' => 'a', 'source_ref' => 'fixture:partial', 'scope' => $scope,
            'spend' => '1.123456', 'impressions' => '10', 'clicks' => '2', 'conversions' => '1'];
        $partial = (new RecordedLedger($scope, [$r]))->jsonSerialize();
        self::assertSame('1.123456', $partial['totals']['spend']);
        self::assertNull($partial['totals']['conversion_value']);
        self::assertNull($partial['ratios']['roas']['exact']);
        self::assertSame('missing_operand_not_zero', $partial['ratios']['roas']['reason']);
        self::assertSame(['numerator' => '1', 'denominator' => '5'], $partial['ratios']['ctr']['exact']);
    }

    public function test_same_source_contribution_cannot_be_renamed_and_counted_twice(): void
    {
        $l = $this->ledger()->jsonSerialize();
        $rows = [];
        foreach ($l['rows'] as $key => $row) {
            $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => 'fixture:same', 'scope' => $l['scope']];
            foreach ($row['metrics'] as $k => $v) {
                $r[$k] = $v['lexeme'];
            }$rows[] = $r;
        }
        $this->expectException(\InvalidArgumentException::class);
        new RecordedLedger($l['scope'], $rows);
    }
}
