<?php

namespace Tests\Feature;

use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\OperationsResearch\PortfolioRatioOptimizer;
use Tests\TestCase;

class PortfolioRatioTest extends TestCase
{
    private function ch(string $id, $curve, float $min = 0, float $max = 1000): array
    {
        return ['channel' => $id, 'display_name' => $id, 'curve' => $curve, 'min' => $min, 'max' => $max, 'current_spend' => 100, 'current_revenue' => 200];
    }

    public function test_one_concave_ratio_chooses_explicit_minimum_not_zero(): void
    {
        $r = (new PortfolioRatioOptimizer)->optimize([$this->ch('a', ResponseCurve::saturation(100, .01, 1000))], 100, 1000);
        $this->assertSame('ok', $r['status']);
        $this->assertEqualsWithDelta(100, $r['actual_spend'], .02);
        $this->assertEqualsWithDelta(1 - exp(-1), $r['ratio'], 1e-5);
        $this->assertFalse($r['search']['outer_global_certificate']);
    }

    public function test_ratio_search_matches_dense_spend_grid_with_bounds_and_interior_peak(): void
    {
        $ch = [$this->ch('low', ResponseCurve::saturation(1, .01, 1000), 100, 100), $this->ch('good', ResponseCurve::saturation(1000, .01, 1000), 0, 900)];
        $r = (new PortfolioRatioOptimizer)->optimize($ch, 100, 1000);
        $this->assertSame('ok', $r['status']);
        $best = 0;
        for ($s = 100; $s <= 1000; $s += .5) {
            $best = max($best, ((1 - exp(-1)) + 1000 * (1 - exp(-.01 * ($s - 100)))) / $s);
        }$this->assertEqualsWithDelta($best, $r['ratio'], .0001);
        $this->assertGreaterThan(100, $r['actual_spend']);
        $this->assertLessThan(1000, $r['actual_spend']);
    }

    public function test_missing_or_infeasible_minimum_abstains(): void
    {
        $ch = [$this->ch('a', ResponseCurve::linear(2, 1000))];
        $this->assertSame('constraints_required', (new PortfolioRatioOptimizer)->optimize($ch, 0, 100)['status']);
        $this->assertSame('constraints_required', (new PortfolioRatioOptimizer)->optimize($ch, 200, 100)['status']);
    }

    public function test_target_ratio_returns_largest_feasible_spend_and_rejects_unreachable_target(): void
    {
        $ch = [$this->ch('a', ResponseCurve::saturation(100, .01, 1000))];
        $r = (new PortfolioRatioOptimizer)->target($ch, 10, 1000, .5);
        $this->assertSame('ok', $r['status']);
        $this->assertGreaterThanOrEqual(.5, $r['ratio']);
        $this->assertLessThan(.0001, abs((100 * (1 - exp(-.01 * $r['actual_spend'])) / $r['actual_spend']) - .5));
        $this->assertSame('infeasible', (new PortfolioRatioOptimizer)->target($ch, 10, 1000, 2)['status']);
    }

    public function test_deterministic_varied_two_channel_cases_match_grid(): void
    {
        for ($case = 0; $case < 8; $case++) {
            $min = 20 + 10 * $case;
            $max = 200.;
            $ch = [$this->ch('a', ResponseCurve::saturation(90 + 7 * $case, .008, 500), $min, $max), $this->ch('b', ResponseCurve::log(20 + 3 * $case, .02, 500), 0, $max)];
            $r = (new PortfolioRatioOptimizer)->optimize($ch, $min, 200);
            $this->assertSame('ok', $r['status']);
            $best = 0;
            for ($a = $min; $a <= 200; $a += 2) {
                for ($b = 0; $a + $b <= 200; $b += 2) {
                    $best = max($best, ($ch[0]['curve']->revenueAt($a) + $ch[1]['curve']->revenueAt($b)) / ($a + $b));
                }
            }
            $this->assertGreaterThanOrEqual($best - .0002,$r['ratio']);
            $this->assertLessThanOrEqual(200.01,$r['actual_spend']);
        }
    }
}
