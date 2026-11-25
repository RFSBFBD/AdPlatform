<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationsResearch\AdvertisingOptimizationService;
use App\Services\OperationsResearch\DTOs\ChannelParameterDto;
use App\Services\OperationsResearch\Strategies\ConvexMarginalReturnOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsResearchTest extends TestCase
{
    public function test_convex_optimizer_computes_exact_kkt_equilibrium(): void
    {
        $optimizer = new ConvexMarginalReturnOptimizer();

        $meta = new ChannelParameterDto(
            channel: 'meta',
            displayName: 'Meta Ads',
            currentSpend: 6000.0,
            currentRevenue: 18000.0,
            currentRoas: 3.0,
            alpha: 40000.0,
            beta: 0.00010
        );

        $google = new ChannelParameterDto(
            channel: 'google',
            displayName: 'Google Ads',
            currentSpend: 4000.0,
            currentRevenue: 16000.0,
            currentRoas: 4.0,
            alpha: 50000.0,
            beta: 0.00009
        );

        $result = $optimizer->optimize(10000.0, [$meta, $google]);

        // Total allocated spend should match total budget ($10,000) within tolerance
        $totalAllocated = array_sum(array_column($result->channelAllocations, 'optimal_spend'));
        $this->assertEqualsWithDelta(10000.0, $totalAllocated, 1.0);

        // Optimal revenue should be greater than or equal to current revenue
        $this->assertGreaterThanOrEqual($result->currentTotalRevenue, $result->optimalTotalRevenue);

        // Frontier curve should have generated points
        $this->assertNotEmpty($result->frontierCurve);
        $this->assertNotEmpty($result->recommendations);
    }

    public function test_api_endpoint_returns_operations_research_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/analytics/operations-research?budget=15000');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'objective_basis_required')
            ->assertJsonPath('data.recommendations', [])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_budget',
                    'current_total_revenue',
                    'current_blended_roas',
                    'optimal_total_revenue',
                    'optimal_blended_roas',
                    'revenue_gain_delta',
                    'channel_allocations',
                    'recommendations',
                    'frontier_curve',
                    'sensitivity_points',
                    'mathematical_model',
                ],
            ]);
    }

    private function metrics(array $override = []): array
    {
        $base = [
            'meta' => ['total_spend' => 5000.0, 'total_revenue' => 15000.0, 'days_with_data' => 30, 'latest_date' => '2026-11-21', 'currencies' => ['USD']],
            'google' => ['total_spend' => 4000.0, 'total_revenue' => 18000.0, 'days_with_data' => 30, 'latest_date' => '2026-11-21', 'currencies' => ['USD']],
        ];
        return array_replace_recursive($base, $override);
    }

    private function opts(array $o = []): array
    {
        return array_merge(['budget' => 9000.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5], $o);
    }

    private function solve(array $metrics, array $opts)
    {
        return (new AdvertisingOptimizationService())->optimizeFromMetrics($metrics, $opts, '2026-11-22');
    }

    public function test_missing_budget_or_assumption_abstains_without_defaults(): void
    {
        foreach ([[], ['budget' => 9000.0], ['saturation_multiplier' => 2.5]] as $o) {
            $r = $this->solve($this->metrics(), $o);
            $this->assertSame('constraints_required', $r->status);
            $this->assertSame([], $r->recommendations);
            $this->assertSame([], $r->channelAllocations);
        }
    }

    public function test_no_data_means_no_numbers(): void
    {
        $r = $this->solve([], $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertSame(0.0, $r->currentTotalRevenue);
        $this->assertSame([], $r->recommendations);
    }

    public function test_two_channels_are_gated_and_labelled_experimental(): void
    {
        $r = $this->solve($this->metrics(), $this->opts());
        $this->assertSame('ok', $r->status);
        $this->assertStringContainsString('EXPERIMENTAL', implode(' ', $r->warnings));
        $this->assertEqualsWithDelta(9000.0, array_sum(array_column($r->channelAllocations, 'current_spend')), 0.01);
    }

    public function test_zero_spend_channel_is_excluded_not_replaced(): void
    {
        $r = $this->solve($this->metrics(['google' => ['total_spend' => 0.0]]), $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertStringContainsString('google excluded', implode(' ', $r->warnings));
    }

    public function test_delayed_data_is_excluded(): void
    {
        $r = $this->solve($this->metrics(['google' => ['latest_date' => '2026-11-10']]), $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertStringContainsString('delayed', implode(' ', $r->warnings));
    }

    public function test_too_few_days_is_insufficient_quality(): void
    {
        $r = $this->solve($this->metrics(['meta' => ['days_with_data' => 5]]), $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertStringContainsString('only 5 of 30 days', implode(' ', $r->warnings));
    }

    public function test_mixed_currency_across_channels_abstains(): void
    {
        $r = $this->solve($this->metrics(['google' => ['currencies' => ['EUR']]]), $this->opts());
        $this->assertSame('mixed_currency', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_infeasible_is_distinct_from_underspend(): void
    {
        $limits = ['meta' => ['max' => 1000.0], 'google' => ['max' => 1000.0]];
        $exact = $this->solve($this->metrics(), $this->opts(['budget' => 9000.0, 'channel_limits' => $limits, 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('infeasible', $exact->status);

        $atMost = $this->solve($this->metrics(), $this->opts([
            'budget' => 9000.0, 'budget_mode' => 'at_most', 'min_marginal_roas' => 1.0, 'channel_limits' => $limits, 'channel_limits_horizon' => 'per_30_days',
        ]));
        $this->assertSame('ok', $atMost->status);
        $this->assertLessThanOrEqual(2000.01, array_sum(array_column($atMost->channelAllocations, 'optimal_spend')));
    }

    public function test_minimum_above_budget_is_infeasible(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 100.0, 'channel_limits' => ['meta' => ['min' => 250.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('infeasible', $r->status);
    }

    public function test_at_most_requires_explicit_floor(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['budget_mode' => 'at_most']));
        $this->assertSame('constraints_required', $r->status);
    }

    public function test_exact_budget_is_fully_allocated(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 12000.0]));
        $this->assertSame('ok', $r->status);
        $this->assertEqualsWithDelta(12000.0, array_sum(array_column($r->channelAllocations, 'optimal_spend')), 5.0);
    }

    public function test_gates_in_effect_are_reported_and_tunable(): void
    {
        config(['or.min_days_with_data' => 3, 'or.allow_partial_window_scaling' => true]);
        $r = $this->solve($this->metrics(['meta' => ['days_with_data' => 5]]), $this->opts());
        $this->assertSame('ok', $r->status);
        $this->assertSame(3, $r->toArray()['gates']['min_days_with_data']);
        $this->assertFalse($r->toArray()['gates']['calibrated']);
    }

    public function test_budget_horizon_is_required_and_daily_is_scaled_by_window(): void
    {
        $o = $this->opts();
        unset($o['budget_horizon']);
        $this->assertSame('constraints_required', $this->solve($this->metrics(), $o)->status);

        $daily = $this->solve($this->metrics(), $this->opts(['budget' => 300.0, 'budget_horizon' => 'daily']));
        $this->assertSame('ok', $daily->status);
        $this->assertEqualsWithDelta(9000.0, $daily->totalBudget, 0.01);
    }

    public function test_user_cap_is_never_exceeded(): void
    {
        $r = $this->solve($this->metrics(), $this->opts([
            'budget' => 9000.0,
            'profile' => ['budget_cap' => 200.0, 'budget_cap_horizon' => 'daily', 'currency' => 'USD'],
        ]));
        $this->assertSame('exceeds_user_cap', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_business_currency_mismatch_abstains_without_conversion(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['profile' => ['currency' => 'EGP']]));
        $this->assertSame('currency_mismatch', $r->status);
        $this->assertSame([], $r->recommendations);
    }

    public function test_matching_profile_currency_is_accepted(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['profile' => ['currency' => 'usd']]));
        $this->assertSame('ok', $r->status);
    }

    public function test_channel_limits_need_their_own_horizon_and_daily_is_scaled(): void
    {
        $limits = ['meta' => ['max' => 20.0], 'google' => ['max' => 20.0]];
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => $limits]));
        $this->assertSame('constraints_required', $r->status);

        // 20/day x 30 = 600 each; exact budget 9000 cannot fit under 1200 total -> infeasible.
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => $limits, 'channel_limits_horizon' => 'daily']));
        $this->assertSame('infeasible', $r->status);
        $this->assertStringContainsString('1200.00', $r->statusMessage);
    }

    public function test_stored_cap_without_valid_period_is_not_ignored(): void
    {
        foreach ([null, '', 'weekly'] as $h) {
            $r = $this->solve($this->metrics(), $this->opts(['profile' => ['budget_cap' => 500.0, 'budget_cap_horizon' => $h]]));
            $this->assertSame('constraints_required', $r->status);
        }
    }

    private function ch(string $n, float $spend, float $rev, float $mult = 2.5, float $min = 0.0, float $max = 1e9): ChannelParameterDto
    {
        $alpha = $rev * $mult;
        $beta = -log(1 - $rev / $alpha) / $spend;
        return new ChannelParameterDto($n, $n, $spend, $rev, $rev / $spend, $alpha, $beta, $min, $max);
    }

    public function test_numeric_optimality_budget_exact_and_equal_marginal_roas(): void
    {
        $a = $this->ch('meta', 5000, 15000);
        $b = $this->ch('google', 4000, 18000);
        $c = $this->ch('tiktok', 2000, 5000, 3.0);
        $r = (new ConvexMarginalReturnOptimizer())->optimize(9000.0, [$a, $b, $c]);
        $this->assertSame('ok', $r->status);
        $spends = array_column($r->channelAllocations, 'optimal_spend');
        $this->assertEqualsWithDelta(9000.0, array_sum($spends), 0.05);
        // All channels interior: marginal returns equal within rounding (KKT stationarity, numerically).
        $m = array_column($r->channelAllocations, 'marginal_roas_optimal');
        $this->assertEqualsWithDelta(max($m), min($m), 0.01);
        // No random feasible allocation on the same budget beats the solver's revenue.
        $best = $r->optimalTotalRevenue;
        mt_srand(7);
        for ($i = 0; $i < 300; $i++) {
            $w = [mt_rand(1, 1000), mt_rand(1, 1000), mt_rand(1, 1000)];
            $t = array_sum($w);
            $rev = $a->calculateRevenue(9000 * $w[0] / $t) + $b->calculateRevenue(9000 * $w[1] / $t) + $c->calculateRevenue(9000 * $w[2] / $t);
            $this->assertLessThanOrEqual($best + 0.5, $rev);
        }
    }

    public function test_binding_limits_hold_and_are_respected(): void
    {
        $a = $this->ch('meta', 5000, 15000, 2.5, 4000.0, 6000.0);
        $b = $this->ch('google', 4000, 18000, 2.5, 0.0, 1e9);
        $r = (new ConvexMarginalReturnOptimizer())->optimize(9000.0, [$a, $b]);
        $this->assertSame('ok', $r->status);
        $x = $r->channelAllocations[0]['optimal_spend'];
        $this->assertGreaterThanOrEqual(4000.0 - 0.01, $x);
        $this->assertLessThanOrEqual(6000.0 + 0.01, $x);
        $this->assertEqualsWithDelta(9000.0, array_sum(array_column($r->channelAllocations, 'optimal_spend')), 0.05);
    }

    public function test_conflicting_min_above_max_is_infeasible(): void
    {
        $a = $this->ch('meta', 5000, 15000, 2.5, 7000.0, 6000.0);
        $b = $this->ch('google', 4000, 18000);
        $this->assertSame('infeasible', (new ConvexMarginalReturnOptimizer())->optimize(9000.0, [$a, $b])->status);
    }

    public function test_budget_edge_values_never_produce_numbers(): void
    {
        $o = new ConvexMarginalReturnOptimizer();
        $ch = [$this->ch('meta', 5000, 15000), $this->ch('google', 4000, 18000)];
        foreach ([0.0, -100.0, NAN, INF, -INF] as $bud) {
            $r = $o->optimize($bud, $ch);
            $this->assertNotSame('ok', $r->status, 'edge budget');
            $this->assertSame([], $r->channelAllocations);
        }
        foreach ([0, -5, 'abc', 'NaN'] as $bud) { // non-numeric strings must not be coerced
            $r = $this->solve($this->metrics(), $this->opts(['budget' => $bud]));
            $this->assertNotSame('ok', $r->status);
            $this->assertSame([], $r->channelAllocations);
        }
        $r = $this->solve($this->metrics(), $this->opts(['saturation_multiplier' => NAN]));
        $this->assertNotSame('ok', $r->status);
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => ['meta' => ['max' => -5]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('constraints_required', $r->status);
    }

    public function test_tiny_and_very_large_budgets_are_exact_or_honestly_refused(): void
    {
        foreach ([1.0, 9000.0, 1e7] as $bud) {
            $r = $this->solve($this->metrics(), $this->opts(['budget' => $bud]));
            if ($r->status === 'ok') {
                $this->assertEqualsWithDelta($bud, array_sum(array_column($r->channelAllocations, 'optimal_spend')), max(0.05, $bud * 1e-4));
                foreach ($r->channelAllocations as $a) {
                    $this->assertFalse(is_nan($a['optimal_revenue']) || is_infinite($a['optimal_revenue']));
                }
            } else {
                $this->assertSame([], $r->channelAllocations);
            }
        }
        // 1e7 must either be solved to the budget or refused, never silently short.
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 1e7]));
        $this->assertContains($r->status, ['ok', 'solver_not_converged', 'budget_beyond_numeric_range']);
    }

    public function test_frontier_has_no_points_outside_feasible_limits(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => ['meta' => ['max' => 5000.0], 'google' => ['max' => 5000.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('ok', $r->status);
        foreach ($r->frontierCurve as $pt) {
            $this->assertLessThanOrEqual(10000.0 + 1, $pt['budget']);
        }
    }

    public function test_output_is_not_labelled_as_proven_kkt_or_real(): void
    {
        $r = $this->solve($this->metrics(), $this->opts());
        $json = json_encode($r->toArray());
        $this->assertStringContainsString('assumed', strtolower($r->mathematicalModel));
        $this->assertStringNotContainsString('maximal yield', $json);
        $this->assertStringNotContainsString('hit diminishing', $json);
        $this->assertTrue($r->certificate['within_bounds']);
        $this->assertLessThanOrEqual($r->certificate['budget_tolerance'], $r->certificate['budget_residual'] + 0.0);
        $this->assertLessThanOrEqual($r->certificate['stationarity_tolerance'], $r->certificate['stationarity_residual']);
        $this->assertArrayHasKey('solver_certificate', $r->toArray());
    }

    public function test_large_budget_is_solved_to_the_budget_or_refused_never_short(): void
    {
        foreach ([200000.0, 1e6] as $bud) {
            $r = $this->solve($this->metrics(), $this->opts(['budget' => $bud]));
            if ($r->status === 'ok') {
                $this->assertEqualsWithDelta($bud, array_sum(array_column($r->channelAllocations, 'optimal_spend')), 0.05);
            } else {
                $this->assertContains($r->status, ['solver_not_converged', 'budget_beyond_numeric_range']);
            }
        }
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 200000.0]));
        $this->assertSame('ok', $r->status);
    }

    public function test_service_level_min_above_max_is_refused(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => ['meta' => ['min' => 6000.0, 'max' => 3000.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('infeasible', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_ok_output_is_always_valid_json_and_finite(): void
    {
        $r = $this->solve($this->metrics(), $this->opts());
        $this->assertNotFalse(json_encode($r->toArray(), JSON_THROW_ON_ERROR));
        $arr = $r->toArray();
        array_walk_recursive($arr, function ($v) {
            if (is_float($v)) {
                $this->assertTrue(is_finite($v));
            }
        });
    }

    public function test_unknown_currency_channel_is_excluded_not_assumed(): void
    {
        $m = $this->metrics();
        $m['meta']['currencies'] = [];
        $r = $this->solve($m, $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertSame([], $r->channelAllocations);
    }

    public function test_partial_window_is_excluded_by_default_and_scaled_only_when_enabled(): void
    {
        $m = ['meta' => ['days_with_data' => 15, 'total_spend' => 2500.0, 'total_revenue' => 7500.0]];
        $r = $this->solve($this->metrics($m), $this->opts());
        $this->assertSame('insufficient_data', $r->status);
        $this->assertSame([], $r->channelAllocations);
        $this->assertStringContainsString('Nothing was extrapolated', implode(' ', $r->warnings));

        config(['or.allow_partial_window_scaling' => true]);
        $full = $this->solve($this->metrics(), $this->opts());
        $part = $this->solve($this->metrics($m), $this->opts());
        $this->assertSame('ok', $part->status);
        $this->assertEqualsWithDelta($full->channelAllocations[0]['optimal_spend'], $part->channelAllocations[0]['optimal_spend'], 0.05);
        $this->assertStringContainsString('scaled linearly', implode(' ', $part->warnings));
    }

    public function test_maintain_band_is_relative_and_reported(): void
    {
        $r = $this->solve($this->metrics(), $this->opts());
        $this->assertArrayHasKey('maintain_band_percent', $r->gates);
        foreach ($r->channelAllocations as $a) {
            $rel = abs($a['delta_spend']) / $a['current_spend'] * 100;
            $this->assertSame($rel <= 5.0, $a['action'] === 'MAINTAIN');
        }
    }

    public function test_limit_on_excluded_or_missing_channel_refuses(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => ['tiktok' => ['max' => 100.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('excluded_channel_has_limits', $r->status);
        $this->assertSame([], $r->channelAllocations);
        $m = $this->metrics();
        $m['tiktok'] = ['total_spend' => 1000.0, 'total_revenue' => 2000.0, 'days_with_data' => 5, 'latest_date' => '2026-11-21', 'currencies' => ['USD']];
        $r = $this->solve($m, $this->opts(['channel_limits' => ['tiktok' => ['min' => 10.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('excluded_channel_has_limits', $r->status);
    }

    public function test_config_file_is_loaded_with_expected_keys(): void
    {
        $this->assertIsArray(config('or'));
        foreach (['min_days_with_data', 'max_staleness_days', 'maintain_band_percent', 'allow_partial_window_scaling'] as $k) {
            $this->assertArrayHasKey($k, config('or'));
        }
    }

    public function test_extreme_budget_on_saturated_curve_gets_explicit_numeric_range_status(): void
    {
        $m = ['meta' => ['total_spend' => 1073.0, 'total_revenue' => 10768.0], 'google' => ['total_spend' => 1507.0, 'total_revenue' => 13931.0]];
        $r = $this->solve($this->metrics($m), $this->opts(['budget' => 125682.0, 'saturation_multiplier' => 1.67]));
        $this->assertSame('budget_beyond_numeric_range', $r->status);
        $this->assertSame([], $r->channelAllocations);
        $this->assertStringContainsString('not a proof', $r->statusMessage);
    }

    public function test_gain_is_measured_against_same_spend_baseline_not_observed_spend(): void
    {
        // observed spend 9000; scenario budget 18000. 'Gain' must not include the extra 9000 of spend.
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 18000.0]));
        $this->assertSame('ok', $r->status);
        $c = $r->comparison;
        $this->assertEqualsWithDelta(9000.0, $c['observed_spend'], 0.01);
        $this->assertEqualsWithDelta(18000.0, $c['reference_spend'], 0.05);
        $this->assertEqualsWithDelta(9000.0, $c['spend_change_vs_observed'], 0.05);
        $this->assertEqualsWithDelta($r->optimalTotalRevenue - $c['baseline_revenue_same_spend'], $r->revenueGainDelta, 0.02);
        $this->assertGreaterThan($c['baseline_revenue_same_spend'], $c['observed_revenue_on_curve'] * 0 + $r->optimalTotalRevenue);
        // revenue change vs observed is larger than the reallocation gain and shown separately
        $this->assertGreaterThan($r->revenueGainDelta, $c['revenue_change_vs_observed']);
        // directives follow the same baseline: channel lifts add up to the reallocation gain
        $sum = array_sum(array_column($r->channelAllocations, 'lift_vs_baseline'));
        $this->assertEqualsWithDelta($r->revenueGainDelta, $sum, 0.05);
        $this->assertStringContainsString('same total spend', $c['definition']);
    }

    public function test_budget_equal_to_observed_spend_has_zero_spend_change_and_nonnegative_gain(): void
    {
        $r = $this->solve($this->metrics(), $this->opts(['budget' => 9000.0]));
        $this->assertEqualsWithDelta(0.0, $r->comparison['spend_change_vs_observed'], 0.05);
        $this->assertEqualsWithDelta($r->comparison['observed_revenue_on_curve'], $r->comparison['baseline_revenue_same_spend'], 0.5);
        $this->assertGreaterThanOrEqual(-0.01, $r->revenueGainDelta);
    }

    public function test_gain_is_signed_when_limits_force_a_worse_mix(): void
    {
        // Force meta to a minimum far above its optimum: result must be worse than the same-spend baseline and say so (negative), not 0.
        $r = $this->solve($this->metrics(), $this->opts(['channel_limits' => ['meta' => ['min' => 8000.0]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertSame('ok', $r->status);
        $this->assertLessThan(0.0, $r->revenueGainDelta);
    }

    public function test_partial_window_message_never_cites_the_14_day_gate_when_scaling_is_off(): void
    {
        $r = $this->solve($this->metrics(['meta' => ['days_with_data' => 12]]), $this->opts());
        $this->assertStringNotContainsString('needs 14', implode(' ', $r->warnings));
        $this->assertStringContainsString('only 12 of 30 days', implode(' ', $r->warnings));
    }
}
