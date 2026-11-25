<?php

namespace Tests\Unit;

use App\Services\Analytics\Curves\ResponseCurve;
use PHPUnit\Framework\TestCase;

class ResponseCurvePeriodTest extends TestCase
{
    public function test_period_curves_preserve_daily_units_for_all_families(): void
    {
        $curves = [ResponseCurve::linear(2, 300), ResponseCurve::log(50, .02, 300), ResponseCurve::power(5, .7, 300), ResponseCurve::saturation(1000, .003, 300)];
        foreach ($curves as $daily) {
            $period = $daily->overDays(30);
            foreach ([0, 10, 100, 250] as $x) {
                $this->assertEqualsWithDelta(30 * $daily->revenueAt($x), $period->revenueAt(30 * $x), 1e-8, $daily->name());
                $this->assertEqualsWithDelta($daily->marginalAt($x), $period->marginalAt(30 * $x), $x === 0 ? 1e5 : 1e-8);
            }
            $this->assertEqualsWithDelta(9000, $period->supportMaxSpend(), 1e-8);
            $lambda = $daily->marginalAt(100);
            if ($daily->name() !== 'linear') {
                $this->assertEqualsWithDelta(3000, $period->spendForMarginal($lambda, 0, 9000), 1e-5);
            }
        }
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResponseCurve::linear(2, 100)->overDays(0);
    }

    public function test_lower_observed_support_scales_with_period(): void
    {
        $d=ResponseCurve::log(50,.02,300,40);
        $this->assertSame(40.0,$d->supportMinSpend());
        $this->assertSame(1200.0,$d->overDays(30)->supportMinSpend());
    }
}
