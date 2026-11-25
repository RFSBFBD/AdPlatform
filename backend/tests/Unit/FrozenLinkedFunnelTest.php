<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\FrozenLinkedFunnel;
use PHPUnit\Framework\TestCase;

final class FrozenLinkedFunnelTest extends TestCase
{
    private function fixture(): array
    {
        $scope = ['currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-11-01/2026-11-01', 'population' => 'synthetic', 'provenance_kind' => 'synthetic_fixture'];
        $in = ['version' => 'linked-funnel-events-v1', 'evidence_id' => 'synthetic', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-11-01/2026-11-01', 'population' => 'synthetic', 'counting_unit' => 'source_events_not_unique_people', 'stages' => []];
        $parent = null;
        foreach (FrozenLinkedFunnel::STAGES as $s) {
            $e = ['event_id' => $s, 'parent_event_id' => $parent, 'occurred_at' => '2026-11-01T10:00:00Z'];
            if ($s === 'purchase') {
                $e['value'] = '9007199254740993.000000001';
            }$in['stages'][$s] = [$e];
            $parent = $s;
        }

        return [$scope, $in];
    }

    public function test_exact_linked_stages_and_parent_coverage_are_not_child_conversion_or_people(): void
    {
        [$s,$in] = $this->fixture();
        $in['stages']['click'][] = ['event_id' => 'click2', 'parent_event_id' => 'impression', 'occurred_at' => '2026-11-01T10:00:01Z'];
        $r = (new FrozenLinkedFunnel)->aggregate($s, $in);
        self::assertSame('2', $r['stages']['click']['count_exact']);
        self::assertSame(['numerator' => '1', 'denominator' => '1'], $r['stages']['click']['transition']['parent_coverage_fraction_exact']);
        self::assertSame('1', $r['stages']['landing']['transition']['parents_without_child_exact']);
        self::assertSame('9007199254740993.000000001', $r['revenue']['value_exact']);
        self::assertNull($r['revenue']['profit']);
        self::assertSame('source_events_not_unique_people', $r['counting_unit']);
        $in['stages']['purchase'] = [];
        $r = (new FrozenLinkedFunnel)->aggregate($s, $in);
        self::assertSame('0', $r['revenue']['value_exact']);
        $in['stages']['purchase'] = null;
        $r = (new FrozenLinkedFunnel)->aggregate($s, $in);
        self::assertNull($r['revenue']['value_exact']);
    }

    public function test_missing_stages_zero_denominator_and_unverified_source_remain_explicit(): void
    {
        [$s,$in] = $this->fixture();
        foreach (FrozenLinkedFunnel::STAGES as $stage) {
            $in['stages'][$stage] = [];
        }$r = (new FrozenLinkedFunnel)->aggregate($s, $in);
        self::assertNull($r['stages']['click']['transition']['parent_coverage_fraction_exact']);
        $in['stages']['purchase'] = null;
        $s['provenance_kind'] = 'recorded_source';
        $r = (new FrozenLinkedFunnel)->aggregate($s, $in);
        self::assertSame('declared_linkage_not_provider_verified', $r['status']);
        self::assertFalse($r['source_population_qualified']);
        self::assertSame('unavailable', $r['stages']['purchase']['status']);
        self::assertSame('unavailable', (new FrozenLinkedFunnel)->build(['snapshot_id' => 's'])['status']);
    }

    public function test_duplicate_unlinked_unknown_stage_out_of_window_or_time_inversion_refuse(): void
    {
        [$s,$in] = $this->fixture();
        $cases = [];
        $b = $in;
        $b['stages']['click'][0]['parent_event_id'] = 'other';
        $cases[] = $b;
        $b = $in;
        $b['stages']['landing'] = null;
        $cases[] = $b;
        $b = $in;
        $b['stages']['click'][0]['event_id'] = 'impression';
        $cases[] = $b;
        $b = $in;
        $b['stages']['click'][0]['occurred_at'] = '2026-10-31T10:00:00Z';
        $cases[] = $b;
        $b = $in;
        $b['stages']['click'][0]['occurred_at'] = '2026-11-01T09:00:00Z';
        $cases[] = $b;
        $b = $in;
        $b['stages']['impression'][0]['occurred_at'] = '2026-11-01T10:00:00+00:99';
        $cases[] = $b;
        foreach ($cases as $b) {
            try {
                (new FrozenLinkedFunnel)->aggregate($s, $b);
                self::fail('invalid linkage accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
