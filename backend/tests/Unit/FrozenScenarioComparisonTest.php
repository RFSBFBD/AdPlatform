<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\FrozenScenarioComparison;
use PHPUnit\Framework\TestCase;

final class FrozenScenarioComparisonTest extends TestCase
{
    public function test_unequal_spend_and_minimum_spend_target_are_not_disguised_as_same_budget_lift(): void
    {
        $r = ['snapshot_id' => 'synthetic', 'problem' => ['target_optimization' => 'minimize_spend'], 'scenarios' => [
            'current' => ['allocation' => ['a' => '10.000001'], 'check' => ['status' => 'checked', 'spend_exact' => '10.000001', 'response_approximate' => 20.]],
            'optimized' => ['allocation' => ['a' => '5.000001'], 'check' => ['status' => 'checked', 'spend_exact' => '5.000001', 'response_approximate' => 10.]],
            'custom' => ['allocation' => ['a' => '0'], 'check' => ['status' => 'rejected', 'spend_exact' => '0', 'response_approximate' => 0.]],
        ]];
        $c = (new FrozenScenarioComparison)->build($r);
        $x = $c['comparisons']['no_change_modeled'];
        self::assertFalse($x['same_total_spend']);
        self::assertSame('-5.000000', $x['spend_change_exact']);
        self::assertSame(['numerator' => '-5000000', 'denominator' => '10000001'], $x['spend_change_fraction_exact']);
        self::assertSame(-.5, $x['response_change_fraction_approximate']);
        self::assertSame('minimize_spend', $x['target_optimization']);
        self::assertNull($x['causal_lift']);
        self::assertSame('unavailable', $c['comparisons']['custom']['status']);
        $r['scenarios']['current']['allocation']['a'] = '0';
        $r['scenarios']['current']['check']['spend_exact'] = '0';
        $r['scenarios']['current']['check']['response_approximate'] = 0.;
        $x = (new FrozenScenarioComparison)->build($r)['comparisons']['no_change_modeled'];
        self::assertNull($x['spend_change_fraction_exact']);
        self::assertNull($x['response_change_fraction_approximate']);
    }
}
