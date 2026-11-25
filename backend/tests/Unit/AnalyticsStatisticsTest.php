<?php

namespace Tests\Unit;

use App\Services\Analytics\KpiEngine;
use App\Services\Analytics\Statistics\ConfidenceIntervals;
use App\Services\Analytics\Statistics\Quantiles;
use PHPUnit\Framework\TestCase;

class AnalyticsStatisticsTest extends TestCase
{
    public function test_normal_quantile_reference_values(): void
    {
        $this->assertEqualsWithDelta(1.959964, Quantiles::normalQuantile(0.975), 1e-5);
        $this->assertEqualsWithDelta(0.0, Quantiles::normalQuantile(0.5), 1e-9);
        $this->assertEqualsWithDelta(-1.644854, Quantiles::normalQuantile(0.05), 1e-5);
    }

    public function test_student_t_quantile_matches_r_reference(): void
    {
        // R: qt(0.975, df) and qt(0.995, 10)
        $this->assertEqualsWithDelta(2.228139, Quantiles::studentTQuantile(0.975, 10), 1e-4);
        $this->assertEqualsWithDelta(2.570582, Quantiles::studentTQuantile(0.975, 5), 1e-3);
        $this->assertEqualsWithDelta(2.042272, Quantiles::studentTQuantile(0.975, 30), 1e-4);
        $this->assertEqualsWithDelta(3.169273, Quantiles::studentTQuantile(0.995, 10), 1e-3);
    }

    public function test_wilson_interval_known_value(): void
    {
        $ci = ConfidenceIntervals::proportion(50, 100, 0.95);
        $this->assertNotNull($ci);
        $this->assertEqualsWithDelta(0.403832, $ci['lower'], 1e-4);
        $this->assertEqualsWithDelta(0.596168, $ci['upper'], 1e-4);
        $this->assertSame(100, $ci['n']);
    }

    public function test_wilson_rejects_impossible_binomial(): void
    {
        // conversions can exceed clicks under platform attribution: binomial framing impossible
        $this->assertNull(ConfidenceIntervals::proportion(120, 100));
        $this->assertNull(ConfidenceIntervals::proportion(10, 0));
    }

    public function test_daily_mean_interval_needs_enough_days_and_variance(): void
    {
        $this->assertNull(ConfidenceIntervals::dailyMean([1.0, 2.0, 3.0])); // below min n
        $this->assertNull(ConfidenceIntervals::dailyMean(array_fill(0, 10, 5.0))); // zero variance: no fake zero-width interval
        $vals = [1.0, 2.0, 1.5, 2.5, 2.0, 1.0, 1.5, 2.0, 2.5, 1.5];
        $ci = ConfidenceIntervals::dailyMean($vals, 0.95, 7);
        $this->assertNotNull($ci);
        $this->assertEqualsWithDelta(array_sum($vals) / 10, $ci['estimate'], 1e-9);
        $this->assertLessThan($ci['estimate'], $ci['lower']);
        $this->assertGreaterThan($ci['estimate'], $ci['upper']);
        $this->assertSame(10, $ci['n']);
    }

    public function test_kpi_engine_known_values_and_types(): void
    {
        $kpi = KpiEngine::fromTotals(1000.0, 50000, 1200, 60, 3400.0, 'EGP');
        $this->assertSame('ACTUAL', $kpi['ctr']->type);
        $this->assertEqualsWithDelta(0.024, $kpi['ctr']->value, 1e-9);
        $this->assertEqualsWithDelta(1000 / 1200, $kpi['cpc']->value, 1e-9);
        $this->assertEqualsWithDelta(20.0, $kpi['cpm']->value, 1e-9);
        $this->assertEqualsWithDelta(0.05, $kpi['cvr']->value, 1e-9);
        $this->assertEqualsWithDelta(1000 / 60, $kpi['cpa']->value, 1e-9);
        $this->assertEqualsWithDelta(3.4, $kpi['roas']->value, 1e-9);
        $this->assertSame('clicks / impressions', $kpi['ctr']->lineage['formula']);
    }

    public function test_kpi_engine_nulls_on_zero_denominators(): void
    {
        $kpi = KpiEngine::fromTotals(0.0, 0, 0, 0, 0.0, 'EGP');
        $this->assertNull($kpi['ctr']->value);
        $this->assertNull($kpi['cpc']->value);
        $this->assertNull($kpi['roas']->value);
        $this->assertNotNull($kpi['ctr']->reason);
        $this->assertSame(0.0, $kpi['spend']->value);
    }
}
