<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Deterministic funnel identities under explicit fixed-rate assumptions, not a fitted budget response. */
final class PrimitiveScenarioGraph
{
    public function evaluate(array $spec): array
    {
        if (array_diff(array_keys($spec), ['snapshot_id', 'currency', 'outcome_definition', 'basis', 'primitives', 'shocks', 'assumption'])
            || ($spec['basis'] ?? null) !== 'budget_cpm_ctr_cvr_value_per_conversion'
            || ($spec['assumption'] ?? null) !== 'fixed_rates_same_population_expected_fractional_credits') {
            throw new InvalidArgumentException('explicit_supported_primitive_graph_required');
        }
        foreach (['snapshot_id', 'currency', 'outcome_definition'] as $key) {
            if (! is_string($spec[$key] ?? null) || $spec[$key] === '') {
                throw new InvalidArgumentException('primitive_graph_scope_required');
            }
        }
        if (! preg_match('/^[A-Z]{3}$/D', $spec['currency'])) {
            throw new InvalidArgumentException('valid_currency_required');
        }
        $fields = ['budget', 'cpm', 'ctr', 'cvr', 'value_per_conversion'];
        if (! is_array($spec['primitives'] ?? null) || array_diff(array_keys($spec['primitives']), $fields) || array_diff($fields, array_keys($spec['primitives']))
            || ! is_array($spec['shocks'] ?? null) || array_diff(array_keys($spec['shocks']), $fields)) {
            throw new InvalidArgumentException('derived_or_unknown_metric_cannot_be_independently_shocked');
        }
        $base = [];
        $changed = [];
        foreach ($fields as $field) {
            $base[$field] = $this->operand($spec['primitives'][$field]);
            $changed[$field] = $base[$field];
        }
        foreach ($spec['shocks'] as $field => $scale) {
            $changed[$field] = $base[$field]->multipliedBy(ExactValue::of($scale)->rational());
        }
        foreach ([$base, $changed] as $values) {
            if ($values['cpm']->isLessThanOrEqualTo(0) || $values['ctr']->isGreaterThan(1) || $values['cvr']->isGreaterThan(1)) {
                throw new InvalidArgumentException('positive_cost_and_probability_rates_within_zero_one_required');
            }
        }

        return ['status' => 'evaluated_assumption_graph', 'type' => 'SCENARIO', 'snapshot_id' => $spec['snapshot_id'], 'currency' => $spec['currency'], 'outcome_definition' => $spec['outcome_definition'],
            'baseline' => $this->derive($base), 'changed' => $this->derive($changed), 'shock_multipliers' => $spec['shocks'],
            'dependencies' => ['impressions' => 'budget*1000/cpm', 'clicks' => 'impressions*ctr', 'conversion_credits' => 'clicks*cvr', 'conversion_value' => 'conversion_credits*value_per_conversion',
                'cpc' => 'budget/clicks', 'cpa' => 'budget/conversion_credits', 'roas' => 'conversion_value/budget'],
            'assumption' => $spec['assumption'], 'model_refit' => false, 'reoptimized' => false, 'probability_of_success' => null, 'causal_claim' => false,
            'limits' => ['Exact arithmetic on stated assumptions, not a prediction or approved financial rounding policy.', 'Rates are held fixed by assumption, not identified advertising response.', 'Credits are expected fractional quantities, not unique people or guaranteed outcomes.', 'CPC/CPA/ROAS are derived, never independent controls. CPC cost shocks at fixed CTR must be expressed as CPM changes.']];
    }

    private function operand(mixed $value): BigRational
    {
        if (! is_array($value)) {
            return ExactValue::of($value)->rational();
        }
        if (array_diff(array_keys($value), ['numerator', 'denominator']) || ! isset($value['numerator'],$value['denominator'])) {
            throw new InvalidArgumentException('explicit_exact_fraction_required');
        }
        $num = ExactValue::of($value['numerator'], true)->rational();
        $den = ExactValue::of($value['denominator'], true)->rational();
        if ($den->isZero()) {
            throw new InvalidArgumentException('nonzero_fraction_denominator_required');
        }

        return $num->dividedBy($den);
    }

    private function derive(array $v): array
    {
        $out = $v;
        $out['impressions'] = $v['budget']->multipliedBy(1000)->dividedBy($v['cpm']);
        $out['clicks'] = $out['impressions']->multipliedBy($v['ctr']);
        $out['conversion_credits'] = $out['clicks']->multipliedBy($v['cvr']);
        $out['conversion_value'] = $out['conversion_credits']->multipliedBy($v['value_per_conversion']);
        foreach (['cpc' => 'clicks', 'cpa' => 'conversion_credits'] as $key => $den) {
            $out[$key] = $out[$den]->isZero() ? null : $v['budget']->dividedBy($out[$den]);
        }
        $out['roas'] = $v['budget']->isZero() ? null : $out['conversion_value']->dividedBy($v['budget']);

        return array_map(fn (?BigRational $n) => $n === null ? null : ExactValue::fraction($n), $out);
    }
}
