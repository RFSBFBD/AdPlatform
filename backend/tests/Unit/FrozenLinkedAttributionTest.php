<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\FrozenLinkedAttribution;
use PHPUnit\Framework\TestCase;

final class FrozenLinkedAttributionTest extends TestCase
{
    public function test_exact_credit_and_values_follow_only_declared_parent_chain(): void
    {
        require_once __DIR__.'/FrozenLinkedFunnelTest.php';
        $t = new FrozenLinkedFunnelTest('test_exact_linked_stages_and_parent_coverage_are_not_child_conversion_or_people');
        [, $in] = (new \ReflectionMethod($t, 'fixture'))->invoke($t);
        $in['stages']['purchase'][] = ['event_id' => 'p2', 'parent_event_id' => 'qualified_lead', 'occurred_at' => '2026-11-01T10:00:01Z', 'value' => '0.000000001'];
        $r = (new FrozenLinkedAttribution)->build(['snapshot_id' => 's', 'linked_funnel' => $in]);
        self::assertSame('computed_declared_single_click_credit', $r['status']);
        self::assertSame(['numerator' => '2', 'denominator' => '1'], $r['credits']['click']['purchase_credit_exact']);
        self::assertSame(['numerator' => '4503599627370496500000001', 'denominator' => '500000000'], $r['declared_purchase_value_exact']);
        self::assertTrue($r['credit_conservation']);
        self::assertTrue($r['value_conservation']);
        self::assertFalse($r['trained_data_driven']);
        self::assertCount(2, $r['paths']);
        $in['stages']['purchase'][1]['value'] = null;
        $r = (new FrozenLinkedAttribution)->build(['snapshot_id' => 's', 'linked_funnel' => $in]);
        self::assertNull($r['credits']['click']['declared_value_credit_exact']);
        self::assertNull($r['declared_purchase_value_exact']);
        self::assertNull($r['value_conservation']);
    }

    public function test_unknown_or_unlinked_chains_never_become_multi_touch_attribution(): void
    {
        $service = new FrozenLinkedAttribution;
        self::assertSame('unavailable', $service->build(['snapshot_id' => 's'])['status']);
        require_once __DIR__.'/FrozenLinkedFunnelTest.php';
        $t = new FrozenLinkedFunnelTest('test_exact_linked_stages_and_parent_coverage_are_not_child_conversion_or_people');
        [, $in] = (new \ReflectionMethod($t, 'fixture'))->invoke($t);
        $in['stages']['click'] = null;
        self::assertSame('unavailable', $service->build(['snapshot_id' => 's', 'linked_funnel' => $in])['status']);
    }
}
