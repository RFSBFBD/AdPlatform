<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\FrozenLinkedFunnel;
use App\Services\Decision\IntegratedDecisionPipeline;
use Tests\TestCase;

final class QuadraticRiskIntegratedTest extends TestCase
{
    private function fixture(): array
    {
        require_once __DIR__.'/ConcaveIntegratedDecisionTest.php';
        $t = new ConcaveIntegratedDecisionTest('test_analytic_log_allocation_has_independent_tangent_bound_and_frozen_replay');
        [$ledger,$spec,$models] = (new \ReflectionMethod($t, 'fixtureProblem'))->invoke($t);
        $spec['allocation_mode'] = 'continuous_linear';
        $spec['quadratic_risk'] = ['mode' => 'response_slope_covariance_penalty', 'lambda' => '1', 'lambda_unit' => 'inverse_response_unit', 'covariance_unit' => 'response_unit_squared_per_currency_squared', 'evidence_id' => 'synthetic-declared-covariance', 'variable_order' => ['a', 'b'], 'covariance' => [['1', '0'], ['0', '1']]];
        foreach ($models as &$m) {
            $m['curve'] = ResponseCurve::linear(10., 10.);
            $m['response_shape'] = 'linear';
        }unset($m);
        config(['solver.python' => \Tests\SolverRuntime::python($this)]);

        return [$ledger, $spec, $models];
    }

    public function test_diagonal_covariance_risk_penalty_changes_allocation_and_exact_bound_replays(): void
    {
        [$l,$p,$m] = $this->fixture();
        $m['a']['curve'] = ResponseCurve::linear(12., 10.);
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertSame('typed_quadratic', $r['route']['solver']);
        self::assertEqualsWithDelta(5.5, (float) $r['scenarios']['optimized']['allocation']['a'], 1e-5);
        self::assertEqualsWithDelta(4.5, (float) $r['scenarios']['optimized']['allocation']['b'], 1e-5);
        self::assertEqualsWithDelta(60.5, $r['scenarios']['optimized']['check']['objective_approximate'], 1e-6);
        self::assertEqualsWithDelta(111., $r['scenarios']['optimized']['check']['response_approximate'], 1e-5);
        self::assertNull($r['scenarios']['optimized']['check']['quadratic_risk_evaluation']['probability']);
        self::assertLessThan(1e-5, $r['certificate']['external_numerical_certificate']['gap_approximate']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_certificate']);
        $p['quadratic_risk']['lambda'] = '2';
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(3., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-5);
        self::assertEqualsWithDelta(2.5, (float) $r['scenarios']['optimized']['allocation']['b'], 1e-5);
    }

    public function test_correlated_psd_covariance_group_and_response_floor_change_known_solution(): void
    {
        [$l,$p,$m] = $this->fixture();
        $p['quadratic_risk']['covariance'] = [['1', '0.5'], ['0.5', '1']];
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(10 / 3, (float) $r['scenarios']['optimized']['allocation']['a'], 1e-5);
        self::assertEqualsWithDelta(100 / 3, $r['scenarios']['optimized']['check']['objective_approximate'], 1e-5);
        $p['minimum_response'] = '80';
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(8., (float) $r['scenarios']['optimized']['check']['spend_exact'], 1e-5);
        $p['minimum_response'] = '1000';
        $bad = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('solver_not_accepted', $bad['status']);
        $p['minimum_response'] = '0';
        $p['groups'] = [['id' => 'a-cap', 'members' => ['a'], 'minimum' => '0', 'maximum' => '2']];
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status'], json_encode($r['solver_result'] ?? []));
        self::assertEqualsWithDelta(2., (float) $r['scenarios']['optimized']['allocation']['a'], 1e-5);
        self::assertEqualsWithDelta(4., (float) $r['scenarios']['optimized']['allocation']['b'], 1e-5);
    }

    public function test_optional_linked_funnel_is_part_of_frozen_snapshot_and_survives_replay_and_sensitivity(): void
    {
        [$l,$p,$m] = $this->fixture();
        $a = $l->jsonSerialize();
        $scope = $a['scope'];
        $in = ['version' => 'linked-funnel-events-v1', 'evidence_id' => 'synthetic', 'currency' => $scope['currency'], 'timezone' => $scope['timezone'], 'reporting_window' => $scope['reporting_window'], 'population' => $scope['population'], 'counting_unit' => 'source_events_not_unique_people', 'stages' => array_fill_keys(FrozenLinkedFunnel::STAGES, [])];
        $rows = [];
        foreach ($a['rows'] as $key => $r) {
            $row = ['key' => $key, 'variable_id' => $r['variable_id'], 'source_ref' => $r['source_ref'], 'reporting_date' => $r['reporting_date'], 'scope' => $scope];
            foreach ($r['metrics'] as $metric => $v) {
                $row[$metric] = $v['lexeme'];
            }$rows[] = $row;
        }
        $ledger = new RecordedLedger($scope, $rows, null, $in);
        self::assertNotSame($a['snapshot_id'], $ledger->jsonSerialize()['snapshot_id']);
        $p['source_snapshot_id'] = $ledger->jsonSerialize()['snapshot_id'];
        foreach ($m as &$v) {
            $v['source_snapshot_id'] = $p['source_snapshot_id'];
        }unset($v);
        $p['budget_shock_multipliers'] = ['1.1'];
        $r = (new IntegratedDecisionPipeline)->run($ledger, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $r['status']);
        self::assertSame('synthetic_linked_event_evidence', $r['consumers']['linked_funnel']['status']);
        self::assertSame('evaluated_budget_shocks', $r['sensitivity']['status']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_consumers']);
    }

    public function test_indefinite_asymmetric_misordered_and_unsupported_risk_contracts_refuse(): void
    {
        [$l,$p,$m] = $this->fixture();
        foreach ([[['1', '2'], ['2', '1']], [['0', '1'], ['1', '0']], [['1', '0.1'], ['0.2', '1']]] as $matrix) {
            $bad = $p;
            $bad['quadratic_risk']['covariance'] = $matrix;
            try {
                new DecisionProblem($bad);
                self::fail('bad covariance accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('covariance_', $e->getMessage());
            }
        }
        $p['quadratic_risk']['covariance'] = [['1', '1'], ['1', '1']];
        new DecisionProblem($p);
        self::assertTrue(true);
        $singular = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $singular['status'], json_encode($singular['solver_result'] ?? []));
        self::assertEqualsWithDelta(25., $singular['scenarios']['optimized']['check']['objective_approximate'], 1e-5);
        $p['quadratic_risk']['covariance'] = [['1', '-0.5'], ['-0.5', '1']];
        $negative = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertSame('checked_reference_scenario', $negative['status']);
        self::assertEqualsWithDelta(75., $negative['scenarios']['optimized']['check']['objective_approximate'], 1e-5);
        $p['quadratic_risk']['variable_order'] = ['b', 'a'];
        $this->expectException(\InvalidArgumentException::class);
        new DecisionProblem($p);
    }
}
