<?php

namespace Tests\Unit;

use App\Services\Analytics\AudienceSaturationEstimator;
use PHPUnit\Framework\TestCase;

/** Synthetic series only. Verifies behavior of the estimator, not any real audience. */
final class AudienceSaturationEstimatorTest extends TestCase
{
    private function series(callable $ctr, int $n = 40): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $f = 1.0 + ($i * 7 % $n) / $n * 3.0; // 1.0..4.0, shuffled order
            $imp = 10000 + ($i * 37 % 11) * 100;
            $noise = (($i * 13 % 7) - 3) * 0.0001;
            $out[] = ['date' => (new \DateTimeImmutable('2026-10-01'))->modify("+$i day")->format('Y-m-d'), 'frequency' => $f, 'impressions' => $imp,
                'clicks' => max(1, round($imp * ($ctr($f) + $noise))), 'spend' => 100 + $i, 'conversions' => 5 + $i % 3];
        }

        return $out;
    }

    public function test_known_breakpoint_is_found_with_interval_and_labelled_estimated(): void
    {
        $r = (new AudienceSaturationEstimator)->estimate($this->series(fn ($f) => $f < 2.5 ? 0.02 : 0.02 - 0.008 * ($f - 2.5)), 'ctr');
        self::assertSame('ok', $r['status']);
        self::assertSame('ESTIMATED', $r['type']);
        self::assertSame('hinge', $r['selected_model']);
        self::assertNotNull($r['saturation_threshold']);
        self::assertEqualsWithDelta(2.5, $r['saturation_threshold'], 0.35);
        self::assertLessThanOrEqual($r['saturation_threshold'], $r['interval']['low']);
        self::assertGreaterThanOrEqual($r['saturation_threshold'], $r['interval']['high']);
        self::assertLessThanOrEqual(4.0, $r['interval']['high']);
        self::assertGreaterThanOrEqual(1.0, $r['interval']['low']);
        self::assertStringContainsString('not_causal', $r['interval']['kind']);
        self::assertContains('observational association, not a causal effect of frequency', $r['limits']);
    }

    public function test_deterministic_for_same_input(): void
    {
        $s = $this->series(fn ($f) => $f < 2.5 ? 0.02 : 0.02 - 0.008 * ($f - 2.5));
        self::assertSame((new AudienceSaturationEstimator)->estimate($s, 'ctr'), (new AudienceSaturationEstimator)->estimate($s, 'ctr'));
    }

    public function test_flat_relationship_gives_no_threshold(): void
    {
        $r = (new AudienceSaturationEstimator)->estimate($this->series(fn ($f) => 0.02), 'ctr');
        self::assertNull($r['saturation_threshold']);
        self::assertNull($r['interval']);
    }

    public function test_improving_after_breakpoint_is_not_reported_as_saturation(): void
    {
        $r = (new AudienceSaturationEstimator)->estimate($this->series(fn ($f) => $f < 2.5 ? 0.02 : 0.02 + 0.008 * ($f - 2.5)), 'ctr');
        self::assertNull($r['saturation_threshold']);
    }

    public function test_short_constant_or_invalid_input_is_refused_not_zero_filled(): void
    {
        $e = new AudienceSaturationEstimator;
        self::assertSame('insufficient_data', $e->estimate(array_slice($this->series(fn ($f) => 0.02), 0, 10), 'ctr')['status']);
        $c = array_map(fn ($d) => ['frequency' => 2.0] + $d, $this->series(fn ($f) => 0.02));
        self::assertSame('insufficient_data', $e->estimate($c, 'ctr')['status']);
        $u = $this->series(fn ($f) => 0.02);
        foreach ($u as $i => $d) {
            if ($i % 2 === 0) {
                $u[$i]['frequency'] = null;
            }
        }
        self::assertSame('insufficient_data', $e->estimate($u, 'ctr')['status']);
        $neg = $this->series(fn ($f) => 0.02);
        $neg[3]['clicks'] = -1;
        self::assertSame('invalid_input', $e->estimate($neg, 'ctr')['status']);
        self::assertSame('invalid_metric', $e->estimate($this->series(fn ($f) => 0.02), 'roas')['status']);
    }

    public function test_cpa_rise_after_breakpoint_is_detected(): void
    {
        $s = $this->series(fn ($f) => 0.02);
        foreach ($s as $i => $d) {
            $f = $d['frequency'];
            $s[$i]['conversions'] = 10;
            $s[$i]['spend'] = 100 * ($f < 2.5 ? 1.0 : 1.0 + 0.5 * ($f - 2.5)) + (($i * 5 % 3) - 1) * 0.5;
        }
        $r = (new AudienceSaturationEstimator)->estimate($s, 'cpa');
        self::assertSame('hinge', $r['selected_model']);
        self::assertNotNull($r['saturation_threshold']);
    }
}
