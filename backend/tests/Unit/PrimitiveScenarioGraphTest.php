<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\PrimitiveScenarioGraph;
use Brick\Math\BigRational;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PrimitiveScenarioGraphTest extends TestCase
{
    private function spec(): array
    {
        return ['snapshot_id' => 'synthetic', 'currency' => 'EGP', 'outcome_definition' => 'synthetic_fractional_credit',
            'basis' => 'budget_cpm_ctr_cvr_value_per_conversion', 'assumption' => 'fixed_rates_same_population_expected_fractional_credits',
            'primitives' => ['budget' => '100', 'cpm' => '10', 'ctr' => '0.1', 'cvr' => '0.2', 'value_per_conversion' => '3'], 'shocks' => ['cpm' => '1.1', 'cvr' => '0.9']];
    }

    private function fraction(array $v): BigRational
    {
        return BigRational::of($v['numerator'])->dividedBy($v['denominator']);
    }

    public function test_coherent_cost_rate_shocks_preserve_funnel_identities_with_exact_fractions(): void
    {
        $r = (new PrimitiveScenarioGraph)->evaluate($this->spec());
        self::assertTrue($this->fraction($r['baseline']['impressions'])->isEqualTo('10000'));
        self::assertTrue($this->fraction($r['baseline']['clicks'])->isEqualTo('1000'));
        self::assertTrue($this->fraction($r['baseline']['conversion_credits'])->isEqualTo('200'));
        self::assertTrue($this->fraction($r['baseline']['conversion_value'])->isEqualTo('600'));
        self::assertTrue($this->fraction($r['changed']['impressions'])->isEqualTo(BigRational::of('100000')->dividedBy(11)));
        self::assertTrue($this->fraction($r['changed']['cpc'])->isEqualTo('0.11'));
        self::assertTrue($this->fraction($r['changed']['cvr'])->isEqualTo('0.18'));
        self::assertTrue($this->fraction($r['changed']['conversion_credits'])->isEqualTo(BigRational::of('1800')->dividedBy(11)));
        foreach (['baseline', 'changed'] as $label) {
            $x = $r[$label];
            self::assertTrue($this->fraction($x['cpc'])->multipliedBy($this->fraction($x['clicks']))->isEqualTo($this->fraction($x['budget'])));
            self::assertTrue($this->fraction($x['roas'])->multipliedBy($this->fraction($x['budget']))->isEqualTo($this->fraction($x['conversion_value'])));
        }
        self::assertFalse($r['reoptimized']);
        self::assertFalse($r['causal_claim']);
        self::assertNull($r['probability_of_success']);
    }

    public function test_exact_fraction_primitives_preserve_same_ledger_baseline_without_decimal_rounding(): void
    {
        $s = $this->spec();
        $s['primitives']['budget'] = ['numerator' => '1', 'denominator' => '3'];
        $s['primitives']['cpm'] = ['numerator' => '10', 'denominator' => '7'];
        $s['shocks'] = [];
        $r = (new PrimitiveScenarioGraph)->evaluate($s);
        self::assertTrue($this->fraction($r['baseline']['budget'])->isEqualTo(BigRational::of(1)->dividedBy(3)));
        self::assertTrue($this->fraction($r['baseline']['impressions'])->isEqualTo(BigRational::of(700)->dividedBy(3)));
        $s['primitives']['budget']['denominator'] = '0';
        $this->expectException(InvalidArgumentException::class);
        (new PrimitiveScenarioGraph)->evaluate($s);
    }

    public function test_derived_shocks_bad_rates_and_zero_cost_reject_and_zero_denominators_remain_null(): void
    {
        foreach (['derived', 'bad_rate', 'zero_cost', 'float'] as $case) {
            $s = $this->spec();
            if ($case === 'derived') {
                $s['shocks']['cpc'] = '1.1';
            }
            if ($case === 'bad_rate') {
                $s['shocks']['cvr'] = '10';
            }
            if ($case === 'zero_cost') {
                $s['primitives']['cpm'] = '0';
            }
            if ($case === 'float') {
                $s['primitives']['budget'] = 100.;
            }
            try {
                (new PrimitiveScenarioGraph)->evaluate($s);
                self::fail('invalid graph admitted');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $s = $this->spec();
        $s['primitives']['ctr'] = '0';
        $r = (new PrimitiveScenarioGraph)->evaluate($s);
        self::assertNull($r['changed']['cpc']);
        self::assertNull($r['changed']['cpa']);
        $s = $this->spec();
        $s['primitives']['budget'] = '0';
        $r = (new PrimitiveScenarioGraph)->evaluate($s);
        self::assertNull($r['changed']['roas']);
    }
}
