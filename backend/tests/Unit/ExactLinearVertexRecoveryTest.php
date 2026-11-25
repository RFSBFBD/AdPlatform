<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\ExactLinearModelCheck;
use App\Services\Decision\ExactLinearVertexRecovery;
use App\Services\Decision\LinearDualResidualBound;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

final class ExactLinearVertexRecoveryTest extends TestCase
{
    private function problem(string $cap = '0.3', string $objective = 'MAXIMIZE_CONVERSIONS', string $target = '0'): DecisionProblem
    {
        $spec = ['objective' => $objective, 'currency' => 'EGP', 'outcome_definition' => 'synthetic', 'reporting_window' => '2026-11-01/2026-11-01', 'timezone' => 'Africa/Cairo', 'source_snapshot_id' => 'synthetic',
            'response_metric' => $objective === 'TARGET_REVENUE' ? 'conversion_value' : 'conversions', 'response_unit' => 'synthetic', 'population' => 'a+b', 'business_estimand' => 'synthetic', 'budget_semantics' => 'period_total_not_daily_rate', 'outcome_kind' => 'other_explicit',
            'budget_cap' => $cap, 'minimum_spend' => '0', 'minimum_response' => '0', 'horizon_days' => 1, 'allocation_mode' => 'continuous_linear', 'interaction_model' => 'separable_no_overlap_claim', 'groups' => [],
            'variables' => [['id' => 'a', 'platform' => 'synthetic', 'campaign_type' => 'synthetic', 'entity_level' => 'campaign', 'entity_id' => 'a', 'current_spend' => '0', 'minimum' => '0', 'maximum' => '0.1', 'menu' => []],
                ['id' => 'b', 'platform' => 'synthetic', 'campaign_type' => 'synthetic', 'entity_level' => 'campaign', 'entity_id' => 'b', 'current_spend' => '0', 'minimum' => '0', 'maximum' => '1', 'menu' => []]]];
        if ($objective === 'TARGET_REVENUE') {
            $spec['target_optimization'] = 'minimize_spend';
            $spec['target'] = $target;
        }

        return new DecisionProblem($spec);
    }

    public function test_independent_python_fraction_vertex_oracle(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/exact-lp-vertices.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $spec = $this->problem($case['cap'])->jsonSerialize();
            unset($spec['problem_version']);
            $spec['variables'][0]['maximum'] = $case['maximum_a'];
            $spec['variables'][1]['maximum'] = $case['maximum_b'];
            $spec['groups'] = [['id' => 'both', 'members' => ['a', 'b'], 'minimum' => '0', 'maximum' => $case['group_cap']]];
            $r = (new ExactLinearVertexRecovery)->recover(new DecisionProblem($spec), $case['numeric_candidate'], [2., 1.]);
            self::assertSame('recovered_candidate', $r['status']);
            foreach (['a', 'b'] as $id) {
                self::assertTrue(BigDecimal::of($r['allocation'][$id])->isEqualTo($case['exact_optimum'][$id]));
            }
            self::assertTrue(BigDecimal::of($r['allocation']['a'])->multipliedBy(2)->plus($r['allocation']['b'])->isEqualTo($case['exact_objective']));
            foreach ([[-1.25, 0., 0., 0., 0.], [.125, -.75, .25, -.5, 0.]] as $dual) {
                $bound = (new LinearDualResidualBound)->maximum(new DecisionProblem($spec), [2., 1.], ['dual_inequality' => $dual, 'dual_lower' => [-.125, .25], 'dual_upper' => [.125, -.25]]);
                self::assertSame('bounded', $bound['status']);
                $upper = BigRational::of($bound['upper_exact']['numerator'])->dividedBy($bound['upper_exact']['denominator']);
                self::assertTrue($upper->isGreaterThanOrEqualTo($case['exact_objective']));
            }
        }
    }

    public function test_exact_represented_linear_target_does_not_admit_a_rounded_up_response(): void
    {
        $problem = $this->problem('1', 'TARGET_REVENUE', '1');
        $models = ['a' => ['curve' => ResponseCurve::linear(1., 1.)],
            'b' => ['curve' => ResponseCurve::linear(3., 1.)]];
        $allocation = ['a' => '0', 'b' => '0.3333333333333333333333333333333333'];
        $check = (new ExactLinearModelCheck)->check($problem, $models, $allocation);
        self::assertSame('rejected', $check['status']);
        self::assertContains('represented_linear_target_not_met', $check['reasons']);
        self::assertSame('0', $check['feasibility_tolerance']);
    }

    public function test_reconstructs_exact_decimal_budget_vertex_without_financial_scale(): void
    {
        $r = (new ExactLinearVertexRecovery)->recover($this->problem(), ['a' => .1, 'b' => .2], [2., 1.]);
        self::assertSame('recovered_candidate', $r['status']);
        self::assertSame(['a' => '0.1', 'b' => '0.2'], $r['allocation']);
        self::assertSame('none', $r['rounding']);
    }

    public function test_does_not_invent_missing_vertex_or_round_nonterminating_rational(): void
    {
        $r = (new ExactLinearVertexRecovery)->recover($this->problem('1'), ['a' => .05, 'b' => .2], [2., 1.]);
        self::assertSame('unavailable', $r['status']);
        self::assertSame('nominated_active_constraints_not_full_rank', $r['reason']);
        $r = (new ExactLinearVertexRecovery)->recover($this->problem('1', 'TARGET_REVENUE', '1'), ['a' => 0., 'b' => 1 / 3], [2., 3.]);
        self::assertSame('unavailable', $r['status']);
        self::assertSame('nonterminating_or_unsupported_exact_vertex', $r['reason']);
    }

    public function test_recovery_never_claims_independent_feasibility_and_rejects_distant_candidate(): void
    {
        $r = (new ExactLinearVertexRecovery)->recover($this->problem(), ['a' => .1, 'b' => .2], [2., 1.]);
        self::assertSame('requires_independent_check', $r['feasibility']);
        $r = (new ExactLinearVertexRecovery)->recover($this->problem(), ['a' => 10., 'b' => 10.], [2., 1.]);
        self::assertSame('unavailable', $r['status']);
    }
}
