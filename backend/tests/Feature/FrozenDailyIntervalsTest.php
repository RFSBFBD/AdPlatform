<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Decision\DecisionEvidenceReplay;
use App\Services\Decision\DecisionProblem;
use App\Services\Decision\FrozenDailyIntervals;
use App\Services\Decision\IntegratedDecisionPipeline;
use Tests\TestCase;

final class FrozenDailyIntervalsTest extends TestCase
{
    private function data(): array
    {
        $l = ['snapshot_id' => 's', 'scope' => ['population' => 'synthetic', 'reporting_window' => '2026-11-01/2026-11-07', 'currency' => 'EGP']];
        $p = [];
        for ($i = 1; $i <= 7; $i++) {
            $p[] = ['date' => sprintf('2026-11-%02d', $i), 'ratios' => array_fill_keys(['spend', 'clicks', 'conversions', 'conversion_value', 'cpa', 'roas'], ['decimal' => (string) $i])];
        }

return [$l, $p];
    }

    public function test_analytic_student_t_interval_and_exact_inputs(): void
    {
        [$l,$p] = $this->data();
        $r = (new FrozenDailyIntervals)->build($l, $p, true);
        $ci = $r['rows'][0]['interval_approximate'];
        self::assertSame('conditional_daily_mean_intervals', $r['status']);
        self::assertEqualsWithDelta(4., $ci['estimate'], 1e-12);
        self::assertEqualsWithDelta(2.00210484, $ci['lower'], 1e-6);
        self::assertEqualsWithDelta(5.99789516, $ci['upper'], 1e-6);
        self::assertSame('1', $r['rows'][0]['observations'][0]['numerator']);
        self::assertFalse($r['prediction_interval']);
        self::assertSame('unweighted_mean_of_defined_daily_ratios_not_pooled_ratio', $r['rows'][4]['estimand']);
    }

    public function test_missing_constant_short_unsafe_and_unqualified_do_not_manufacture_intervals(): void
    {
        [$l,$p] = $this->data();
        self::assertSame('unavailable', (new FrozenDailyIntervals)->build($l, $p, false)['status']);
        foreach (['short', 'missing', 'constant', 'unsafe'] as $mode) {
            $q = $p;
            if ($mode === 'short') {
                array_pop($q);
            }foreach ($q as &$v) {
                if ($mode === 'constant') {
                    $v['ratios']['spend']['decimal'] = '3';
                }if ($mode === 'unsafe') {
                    $v['ratios']['spend']['decimal'] = '9007199254740993';
                }
            }unset($v);
            if ($mode === 'missing') {
                $q[3]['ratios']['spend'] = ['decimal' => null];
            }$r = (new FrozenDailyIntervals)->build($l, $q, true);
            self::assertNull($r['rows'][0]['interval_approximate']);
            self::assertNotNull($r['rows'][0]['reason']);
        }
    }

    public function test_common_consumers_and_replay_keep_conditional_interval_separate_from_probabilities(): void
    {
        require_once __DIR__.'/DeclaredHierarchyIntegratedTest.php';
        $t = new DeclaredHierarchyIntegratedTest('test_parent_constraints_compile_to_atomic_sums_without_double_counting_and_replay');
        [$l,$p,$m] = (new \ReflectionMethod($t, 'fixture'))->invoke($t);
        $r = (new IntegratedDecisionPipeline)->run($l, new DecisionProblem($p), $m);
        self::assertArrayHasKey('confidence_interval', $r['consumers']['charts']);
        self::assertNull($r['consumers']['analyst_input']['confidence']['allocation_probability']);
        self::assertTrue((new DecisionEvidenceReplay)->replay($r)['matches_consumers']);
    }
}
