<?php
// Adversarial OR probes. Drop into backend/tests/Feature/. Each test states the CORRECT expected behaviour.
// Run (php-wasm sandbox): vendor/phpunit/phpunit/phpunit tests/Feature/OrAdversarialTest.php
namespace Tests\Feature;

use App\Services\OperationsResearch\AdvertisingOptimizationService as S;
use Tests\TestCase;

class OrAdversarialTest extends TestCase
{
    private const TODAY = '2026-11-22';

    private function m($sp, $rv, $days = 30, $cur = ['USD']): array
    {
        return ['total_spend' => $sp, 'total_revenue' => $rv, 'days_with_data' => $days, 'latest_date' => '2026-11-21', 'currencies' => $cur];
    }
    private function base(): array
    {
        return ['meta' => $this->m(5000, 15000), 'google' => $this->m(4000, 18000)];
    }
    private function opt(array $o = []): array
    {
        return array_merge(['budget' => 9000.0, 'budget_horizon' => 'per_30_days', 'saturation_multiplier' => 2.5], $o);
    }
    private function run1(array $met, array $o)
    {
        return (new S())->optimizeFromMetrics($met, $o, self::TODAY);
    }
    private function rev($a, $b, $s) { return $a * (1 - exp(-$b * $s)); }

    /** Exact budget: allocation must sum to the budget within 1e-6 (or the result must abstain). */
    public function test_exact_budget_sum_is_tight(): void
    {
        mt_srand(7);
        for ($k = 0; $k < 200; $k++) {
            $met = [];
            foreach (['meta', 'google', 'tiktok'] as $p) $met[$p] = $this->m(mt_rand(1000, 20000), mt_rand(2000, 60000));
            $B = mt_rand(3000, 60000) * 1.0;
            $r = $this->run1($met, $this->opt(['budget' => $B, 'saturation_multiplier' => 1.5 + mt_rand(0, 40) / 10]));
            if ($r->status !== 'ok') continue;
            $sum = array_sum(array_column($r->channelAllocations, 'optimal_spend'));
            $this->assertEqualsWithDelta($B, $sum, 0.011, "case $k budget $B sum $sum");
        }
    }

    /** exact mode must not report ok when the allocation does not spend the stated budget. */
    public function test_exact_large_budget_does_not_silently_underspend(): void
    {
        $r = $this->run1($this->base(), $this->opt(['budget' => 200000.0]));
        if ($r->status === 'ok') {
            $sum = array_sum(array_column($r->channelAllocations, 'optimal_spend'));
            $this->assertEqualsWithDelta(200000.0, $sum, 0.011, 'status ok but budget not spent');
        } else {
            $this->assertNotSame([], (string) $r->statusMessage);
        }
    }

    public function test_min_greater_than_max_is_rejected(): void
    {
        $r = $this->run1($this->base(), $this->opt(['channel_limits' => ['meta' => ['min' => 6000, 'max' => 1000]], 'channel_limits_horizon' => 'per_30_days']));
        $this->assertNotSame('ok', $r->status);
    }

    public function test_frontier_points_respect_minimum_limits(): void
    {
        $r = $this->run1($this->base(), $this->opt(['channel_limits' => ['meta' => ['min' => 4000, 'max' => 6000], 'google' => ['min' => 4000, 'max' => 6000]], 'channel_limits_horizon' => 'per_30_days']));
        if ($r->status === 'ok') foreach ($r->frontierCurve as $p) $this->assertGreaterThanOrEqual(8000 - 1e-6, $p['budget'], 'frontier point below sum of minimums is infeasible');
        $this->assertTrue(true);
    }

    public function test_non_finite_inputs_abstain(): void
    {
        foreach ([NAN, INF, -INF] as $B) {
            $r = $this->run1($this->base(), $this->opt(['budget' => $B]));
            $this->assertNotSame('ok', $r->status);
            $this->assertNotFalse(json_encode($r->toArray()), 'result must be JSON-serialisable');
        }
    }

    public function test_partial_window_is_not_silent(): void
    {
        $r = $this->run1(['meta' => $this->m(2500, 7500, 14), 'google' => $this->m(2000, 9000, 14)], $this->opt());
        $ok = $r->status !== 'ok' || stripos(json_encode($r->warnings), 'days') !== false;
        $this->assertTrue($ok, '14 days of data against a 30-day budget must abstain or warn/normalise explicitly');
    }

    public function test_unknown_currency_abstains(): void
    {
        $r = $this->run1(['meta' => $this->m(5000, 15000, 30, []), 'google' => $this->m(4000, 18000, 30, ['EUR'])], $this->opt());
        $this->assertNotSame('ok', $r->status);
        $r = $this->run1(['meta' => $this->m(5000, 15000, 30, []), 'google' => $this->m(4000, 18000, 30, [])], $this->opt(['profile' => ['currency' => 'USD']]));
        $this->assertNotSame('ok', $r->status);
    }

    public function test_titles_do_not_hardcode_dollar_for_other_currency(): void
    {
        $r = $this->run1(['meta' => $this->m(5000, 15000, 30, ['EUR']), 'google' => $this->m(4000, 18000, 30, ['EUR'])], $this->opt());
        if ($r->status === 'ok' && $r->recommendations) $this->assertStringNotContainsString('$', $r->recommendations[0]['title']);
        $this->assertTrue(true);
    }

    /** Full KKT with complementarity incl. bound-active channels, against a fine brute-force grid (3 channels). */
    public function test_kkt_and_bruteforce_with_limits(): void
    {
        $cases = [
            [['meta' => ['min' => 0, 'max' => 3000]], 12000],
            [['google' => ['min' => 5000, 'max' => 9000]], 12000],
            [[], 7000],
        ];
        foreach ($cases as [$lim, $B]) {
            $met = ['meta' => $this->m(6000, 12000), 'google' => $this->m(5000, 20000), 'tiktok' => $this->m(3000, 4000)];
            $mult = 3.0;
            $r = $this->run1($met, $this->opt(['budget' => (float) $B, 'saturation_multiplier' => $mult, 'channel_limits' => $lim, 'channel_limits_horizon' => 'per_30_days']));
            $this->assertSame('ok', $r->status);
            $P = [];
            foreach ($met as $p => $mm) { $a = $mm['total_revenue'] * $mult; $P[$p] = [$a, -log(1 - 1 / $mult) / $mm['total_spend']]; }
            $names = array_keys($P);
            $lo = []; $hi = [];
            foreach ($names as $n) { $lo[$n] = $lim[$n]['min'] ?? 0; $hi[$n] = $lim[$n]['max'] ?? $B; }
            $best = 0; $N = 300;
            for ($i = 0; $i <= $N; $i++) for ($j = 0; $j <= $N - $i; $j++) {
                $x = [$i * $B / $N, $j * $B / $N, ($N - $i - $j) * $B / $N]; $ok = true; $t = 0;
                foreach ($names as $q => $n) { if ($x[$q] < $lo[$n] - 1e-9 || $x[$q] > $hi[$n] + 1e-9) { $ok = false; break; } $t += $this->rev($P[$n][0], $P[$n][1], $x[$q]); }
                if ($ok && $t > $best) $best = $t;
            }
            $mine = 0; $lam = $r->equilibriumLambda;
            foreach ($r->channelAllocations as $a) {
                $n = $a['channel']; $s = $a['optimal_spend']; $mine += $this->rev($P[$n][0], $P[$n][1], $s);
                $this->assertGreaterThanOrEqual($lo[$n] - 0.011, $s); $this->assertLessThanOrEqual($hi[$n] + 0.011, $s);
                $marg = $P[$n][0] * $P[$n][1] * exp(-$P[$n][1] * $s);
                if ($s > $lo[$n] + 0.01 && $s < $hi[$n] - 0.01) $this->assertEqualsWithDelta($lam, $marg, 1e-3 * max(1, $lam), "interior marginal $n");
                elseif ($s <= $lo[$n] + 0.01) $this->assertLessThanOrEqual($lam + 1e-3, $marg, "at-min $n marginal must be <= lambda");
                else $this->assertGreaterThanOrEqual($lam - 1e-3, $marg, "at-max $n marginal must be >= lambda");
            }
            $this->assertGreaterThanOrEqual($best * (1 - 1e-5), $mine, 'solver must be within grid optimum');
        }
    }

    /** at_most: spends less when floor binds; complementarity: spent < budget implies marginal == floor. */
    public function test_at_most_complementarity(): void
    {
        foreach ([1.0, 2.0, 3.0] as $fl) {
            $r = $this->run1($this->base(), $this->opt(['budget' => 30000.0, 'budget_mode' => 'at_most', 'min_marginal_roas' => $fl]));
            $this->assertSame('ok', $r->status);
            $sum = array_sum(array_column($r->channelAllocations, 'optimal_spend'));
            $this->assertLessThan(30000.0, $sum);
            foreach ($r->channelAllocations as $a) $this->assertEqualsWithDelta($fl, $a['marginal_roas_optimal'], 1e-3);
        }
    }
}
