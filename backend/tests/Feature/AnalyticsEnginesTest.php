<?php

namespace Tests\Feature;

use App\Services\Analytics\AnomalyDetector;
use App\Services\Analytics\Curves\ResponseCurve;
use App\Services\Analytics\DataQualityEngine;
use App\Services\Analytics\DiagnosticsEngine;
use App\Services\Analytics\ForecastingEngine;
use App\Services\Analytics\ResponseCurveFitter;
use App\Services\Analytics\ScenarioEngine;
use Tests\TestCase;

class AnalyticsEnginesTest extends TestCase
{
    public function test_diagnostics_unknown_numerators_are_not_zero_or_healthy(): void
    {
        $engine=new DiagnosticsEngine();$full=['spend'=>100,'impressions'=>10000,'clicks'=>100,'conversions'=>10,'conversion_value'=>200];
        $r=$engine->diagnose(['impressions'=>10000,'clicks'=>100],$full,['days_current'=>14,'days_baseline'=>14]);
        foreach(['spend','cpc','cvr','cpa','aov','roas'] as $k){$this->assertNull($r['signals'][$k]['current']);}
        $this->assertSame('insufficient_data',$r['health']);$this->assertSame('INSUFFICIENT_DATA',$r['decision_state']);
        foreach([-1,'bad',INF] as $v){$bad=$full;$bad['conversion_value']=$v;$r=$engine->diagnose($bad,$full,['days_current'=>14,'days_baseline'=>14]);$this->assertNull($r['signals']['roas']['current']);$this->assertNotSame('healthy',$r['health']);}
    }

    /* ---------------- DataQualityEngine ---------------- */

    public function test_quality_scores_clean_channel_at_100(): void
    {
        $dq = new DataQualityEngine();
        $r = $dq->scoreChannel([
            'days_with_data' => 30, 'window_days' => 30,
            'latest_date' => now()->subDay()->toDateString(), 'today' => now()->toDateString(),
            'currencies' => ['EGP'], 'has_conversion_value' => true, 'duplicate_rows' => 0, 'syncs_failed' => 0,
        ]);
        $this->assertSame(100.0, $r['score']);
        $this->assertSame('high', $r['level']);
        $this->assertSame('1.0', $r['rubric_version']);
    }

    public function test_quality_deductions_are_deterministic(): void
    {
        $dq = new DataQualityEngine();
        $r = $dq->scoreChannel([
            'days_with_data' => 20, 'window_days' => 30, // 10 missing days -> -20
            'latest_date' => now()->subDays(4)->toDateString(), 'today' => now()->toDateString(), // 3 stale beyond 1 -> -20 (capped 20)
            'currencies' => ['EGP', 'USD'], // -25
            'has_conversion_value' => false, // -15
            'duplicate_rows' => 0, 'syncs_failed' => 0,
        ]);
        $this->assertSame(100.0 - 20 - 20 - 25 - 15, $r['score']);
    }

    public function test_portfolio_gate_blocks_below_threshold(): void
    {
        $dq = new DataQualityEngine();
        $low = $dq->scoreChannel([
            'days_with_data' => 10, 'window_days' => 30, 'latest_date' => null, 'today' => '2026-11-23',
            'currencies' => [], 'has_conversion_value' => false, 'duplicate_rows' => 2, 'syncs_failed' => 0,
        ]);
        $p = $dq->portfolio(['meta' => $low]);
        $this->assertFalse($p['optimize_allowed']);
        $this->assertNotNull($p['gate_reason']);
        $good = $dq->scoreChannel([
            'days_with_data' => 30, 'window_days' => 30, 'latest_date' => '2026-11-22', 'today' => '2026-11-23',
            'currencies' => ['EGP'], 'has_conversion_value' => true, 'duplicate_rows' => 0, 'syncs_failed' => 0,
        ]);
        $this->assertTrue($dq->portfolio(['meta' => $good])['optimize_allowed']);
    }

    /* ---------------- AnomalyDetector ---------------- */

    public function test_anomaly_detector_abstains_on_short_series(): void
    {
        $r = (new AnomalyDetector())->analyze([1.0, 2.0, 3.0]);
        $this->assertSame('insufficient_history', $r['iqr']['status']);
        $this->assertSame([], $r['flagged_points']);
    }

    public function test_anomaly_detector_flags_injected_spike_and_shift(): void
    {
        $series = array_fill(0, 30, 100.0);
        foreach (range(0, 29) as $i) {
            $series[$i] += ($i % 3) - 1; // small deterministic wiggle
        }
        $series[15] = 500.0; // spike
        $r = (new AnomalyDetector())->analyze($series);
        $flaggedIdx = array_column($r['flagged_points'], 'index');
        $this->assertContains(15, $flaggedIdx);
        $this->assertSame('ok', $r['iqr']['status']);

        // Level shift at index 20
        $shift = array_merge(array_fill(0, 20, 50.0), array_fill(0, 20, 90.0));
        foreach (range(0, 39) as $i) {
            $shift[$i] += ($i % 3) - 1;
        }
        $r2 = (new AnomalyDetector())->analyze($shift);
        $this->assertTrue($r2['change_point']['detected']);
        $this->assertEqualsWithDelta(20, $r2['change_point']['index'], 1);
        $this->assertSame('up', $r2['change_point']['direction']);
    }

    public function test_anomaly_detector_quiet_on_constant_series(): void
    {
        $r = (new AnomalyDetector())->analyze(array_fill(0, 30, 42.0));
        $this->assertSame([], $r['flagged_points']);
        $this->assertSame('degenerate', $r['iqr']['status']); // honestly reports non-applicability
    }

    /* ---------------- DiagnosticsEngine ---------------- */

    public function test_diagnostics_pre_click_pattern(): void
    {
        $d = new DiagnosticsEngine();
        $cur = ['spend' => 1000, 'impressions' => 50000, 'clicks' => 600, 'conversions' => 60, 'conversion_value' => 3000];
        $base = ['spend' => 1000, 'impressions' => 50000, 'clicks' => 1100, 'conversions' => 110, 'conversion_value' => 5500];
        // cpm flat, ctr down ~45%, cpc up ~83%, cvr flat (both 0.1)
        $r = $d->diagnose($cur, $base, ['days_current' => 14, 'days_baseline' => 14, 'entity' => 'meta']);
        $this->assertSame('pre_click_deterioration', $r['classification']['pattern']);
        $this->assertContains('creative fatigue or weaker creative', $r['classification']['hypotheses']);
        $this->assertSame('INVESTIGATE', $r['decision_state']);
    }

    public function test_diagnostics_post_click_pattern(): void
    {
        $d = new DiagnosticsEngine();
        $cur = ['spend' => 1000, 'impressions' => 50000, 'clicks' => 1000, 'conversions' => 60, 'conversion_value' => 3000];
        $base = ['spend' => 1000, 'impressions' => 50000, 'clicks' => 1000, 'conversions' => 100, 'conversion_value' => 5000];
        // ctr/cpc flat, cvr -40%, cpa +67%
        $r = $d->diagnose($cur, $base, ['days_current' => 14, 'days_baseline' => 14]);
        $this->assertSame('post_click_deterioration', $r['classification']['pattern']);
        $this->assertContains('conversion tracking gap', $r['classification']['hypotheses']);
    }

    public function test_diagnostics_insufficient_data_gate(): void
    {
        $d = new DiagnosticsEngine();
        $r = $d->diagnose(['spend' => 1], ['spend' => 1], ['days_current' => 3, 'days_baseline' => 14]);
        $this->assertSame('INSUFFICIENT_DATA', $r['decision_state']);
        $this->assertSame('insufficient_data', $r['health']);
        $this->assertNull($r['classification']);
    }

    /* ---------------- ForecastingEngine ---------------- */

    public function test_forecast_abstains_below_min_history(): void
    {
        $r = (new ForecastingEngine())->forecast([1.0, 2.0, 3.0], 7);
        $this->assertSame('insufficient_data', $r['status']);
        $this->assertSame([], $r['points']);
    }

    public function test_forecast_recovers_linear_trend_with_intervals(): void
    {
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = 100.0 + 2.0 * $i; // clean trend beats naive on holdout
        }
        $r = (new ForecastingEngine())->forecast($series, 7);
        $this->assertSame('ok', $r['status']);
        $this->assertSame('linear_trend', $r['method']);
        $this->assertSame('prediction', $r['interval_kind']);
        $first = $r['points'][0];
        $this->assertEqualsWithDelta(100 + 2 * 30, $first['expected'], 1.0);
        $this->assertNotNull($r['method_selection']['naive_rmse']);
        $this->assertCount(7, $r['points']);
    }

    /* ---------------- ResponseCurveFitter ---------------- */

    public function test_curve_fitter_recovers_saturation_model(): void
    {
        $true = ResponseCurve::saturation(5000.0, 0.002, 500.0);
        $pts = [];
        for ($i = 1; $i <= 30; $i++) {
            $x = 20.0 + 15.0 * $i; // 35..470 spend range
            $y = $true->revenueAt($x) * (1 + 0.02 * (($i % 5) - 2)); // +-4% deterministic noise
            $pts[] = ['spend' => $x, 'response' => $y];
        }
        $r = (new ResponseCurveFitter())->fit($pts);
        $this->assertSame('ok', $r['status']);
        $this->assertContains($r['model'], ['saturation', 'log', 'power']); // concave family recovered
        $this->assertLessThan(0.5, $r['validation']['validation_rmse_rel']);
        $this->assertNotEmpty($r['candidates']);
        // fitted curve approximates the true one inside support
        $this->assertEqualsWithDelta($true->revenueAt(200), $r['curve']->revenueAt(200), $true->revenueAt(200) * 0.25);
    }

    public function test_curve_fitter_abstains_on_flat_or_short_history(): void
    {
        $f = new ResponseCurveFitter();
        $short = array_map(fn($i) => ['spend' => 10.0 * $i, 'response' => 100.0 * $i], range(1, 5));
        $this->assertSame('insufficient_data', $f->fit($short)['status']);
        $flat = array_map(fn($i) => ['spend' => 100.0, 'response' => 300.0], range(1, 30));
        $this->assertSame('insufficient_data', $f->fit($flat)['status']); // 1 spend level
    }

    /* ---------------- ScenarioEngine ---------------- */

    public function test_sensitivity_labels_are_deterministic(): void
    {
        $se = new ScenarioEngine();
        $mk = fn(float $a, float $b) => ResponseCurve::saturation($a, $b, 5000.0);
        $channels = [
            ['channel' => 'meta', 'curve' => $mk(10000, 0.0005), 'min' => 0.0, 'max' => 100000.0],
            ['channel' => 'google', 'curve' => $mk(8000, 0.0003), 'min' => 0.0, 'max' => 100000.0],
        ];
        $solver = function (array $chs, float $budget) {
            // equal-marginal water-filling for saturation curves (same math as the OR solver)
            $lo = 1e-12;
            $hi = 0.0;
            foreach ($chs as $c) {
                $hi = max($hi, $c['curve']->parameters()['a'] * $c['curve']->parameters()['b']);
            }
            $hi = max($hi * 1.5, 10.0);
            for ($i = 0; $i < 200; $i++) {
                $mid = ($lo + $hi) / 2;
                $sum = 0.0;
                foreach ($chs as $c) {
                    $sum += $c['curve']->spendForMarginal($mid, $c['min'], $c['max']);
                }
                if (abs($sum - $budget) <= 1e-9 * max(1, $budget)) {
                    break;
                }
                $sum > $budget ? $lo = $mid : $hi = $mid;
            }
            $lam = ($lo + $hi) / 2;
            $out = [];
            foreach ($chs as $c) {
                $out[$c['channel']] = $c['curve']->spendForMarginal($lam, $c['min'], $c['max']);
            }
            return $out;
        };
        $r = $se->sensitivity($channels, 10000.0, $solver);
        $this->assertSame('ok', $r['status']);
        $this->assertContains($r['stability'], ['stable', 'moderately_sensitive', 'highly_sensitive']);
        $this->assertCount(4, $r['runs']); // 2 channels x 2 scales
        $this->assertStringContainsString('Not a probability', $r['label']);
    }

    public function test_stress_range_abstains_for_assumed_curves(): void
    {
        $se = new ScenarioEngine();
        $r = $se->stressRange(
            [['channel' => 'meta', 'curve' => ResponseCurve::saturation(10000, 0.0005, 5000), 'validation_rmse_rel' => null]],
            ['meta' => 4000.0]
        );
        $this->assertSame('partial', $r['status']);
        $this->assertNull($r['channels'][0]['best']);
        $this->assertNotNull($r['channels'][0]['reason']); // no invented uncertainty
    }
}
