<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\SameBudgetNoChangeScenario;
use PHPUnit\Framework\TestCase;

final class SameBudgetNoChangeScenarioTest extends TestCase
{
    private function fixture(string $a = '6', string $b = '4', string $mode = 'continuous_linear'): array
    {
        $spec = ['currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-11-01/2026-11-01', 'outcome_definition' => 'synthetic-credit', 'outcome_kind' => 'other_explicit', 'population' => 'a+b',
            'source_snapshot_id' => 's', 'objective' => 'MAXIMIZE_CONVERSIONS', 'response_metric' => 'conversions', 'response_unit' => 'credit', 'business_estimand' => 'synthetic', 'budget_semantics' => 'period_total_not_daily_rate', 'budget_cap' => '20', 'minimum_spend' => '0', 'minimum_response' => '0', 'horizon_days' => 1, 'allocation_mode' => $mode, 'interaction_model' => 'separable_no_overlap_claim', 'groups' => [], 'variables' => []];
        foreach (['a' => $a, 'b' => $b] as $id => $spend) {
            $spec['variables'][] = ['id' => $id, 'platform' => 'synthetic', 'campaign_type' => 'synthetic', 'entity_level' => 'campaign', 'entity_id' => $id, 'current_spend' => $spend, 'minimum' => '0', 'maximum' => '20', 'menu' => $mode === 'finite_menu' ? ['0', '5', '10', '20'] : []];
        }

        return [$spec, ['a' => ['curve' => ResponseCurve::linear(2., 20.)], 'b' => ['curve' => ResponseCurve::linear(1., 20.)]]];
    }

    public function test_scales_current_shares_exactly_to_checked_target_not_to_budget_cap(): void
    {
        [$s,$m] = $this->fixture();
        $r = (new SameBudgetNoChangeScenario)->build(new DecisionProblem($s), $m, ['check' => ['status' => 'checked', 'spend_exact' => '15']]);
        self::assertSame('checked_fixed_share_reference', $r['status']);
        self::assertSame(['a' => '9', 'b' => '6'], $r['allocation']);
        self::assertSame('15', $r['check']['spend_exact']);
        self::assertSame(24., $r['check']['response_approximate']);
        self::assertSame(['numerator' => '3', 'denominator' => '2'], $r['scale_exact']);
    }

    public function test_zero_nonterminating_and_unchecked_target_refuse_without_arbitrary_rounding(): void
    {
        $e = new SameBudgetNoChangeScenario;
        [$s,$m] = $this->fixture('0', '0');
        $target = ['check' => ['status' => 'checked', 'spend_exact' => '1']];
        self::assertSame('positive_current_spend_required_for_defined_shares', $e->build(new DecisionProblem($s), $m, $target)['reason']);
        [$s,$m] = $this->fixture('1', '2');
        self::assertSame('proportional_allocation_requires_nonterminating_decimal_no_rounding_policy', $e->build(new DecisionProblem($s), $m, $target)['reason']);
        self::assertSame('checked_optimized_scenario_required', $e->build(new DecisionProblem($s), $m, null)['reason']);
    }

    public function test_original_menu_group_and_target_constraints_are_not_relaxed_to_create_baseline(): void
    {
        $e = new SameBudgetNoChangeScenario;
        [$s,$m] = $this->fixture('6', '4', 'finite_menu');
        $target = ['check' => ['status' => 'checked', 'spend_exact' => '15']];
        $r = $e->build(new DecisionProblem($s), $m, $target);
        self::assertSame('unavailable', $r['status']);
        self::assertContains('allocation_not_in_menu:a', $r['check']['reasons']);
        [$s,$m] = $this->fixture();
        $s['groups'] = [['id' => 'a-cap', 'members' => ['a'], 'minimum' => '0', 'maximum' => '8']];
        self::assertSame('unavailable', $e->build(new DecisionProblem($s), $m, $target)['status']);
        $s['groups'] = [];
        $s['minimum_response'] = '25';
        self::assertSame('unavailable', $e->build(new DecisionProblem($s), $m, $target)['status']);
        $s['minimum_response'] = '0';
        $s['objective'] = 'TARGET_REVENUE';
        $s['response_metric'] = 'conversion_value';
        $s['target'] = '25';
        $s['target_optimization'] = 'maximize_response';
        self::assertSame('unavailable', $e->build(new DecisionProblem($s), $m, $target)['status']);
    }
}
