<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigRational;

/** Exact rule credit over explicitly linked selected clicks only, not trained or causal attribution. */
final class FrozenLinkedAttribution
{
    public function build(array $ledger): array
    {
        $base = ['version' => 'frozen-linked-single-click-attribution-v1', 'snapshot_id' => $ledger['snapshot_id'], 'model' => 'declared_linked_parent_click', 'causal_lift' => null, 'trained_data_driven' => false, 'operational_approval' => false];
        $f = $ledger['linked_funnel'] ?? null;
        if (! is_array($f)) {
            return $base + ['status' => 'unavailable', 'reason' => 'explicit_linked_funnel_required', 'credits' => []];
        }
        // The extension is a parent chain, not a complete multi-touch journey. Do not invent first/last-touch choice.
        foreach (['click', 'landing', 'lead', 'qualified_lead', 'purchase'] as $stage) {
            if (($f['stages'][$stage] ?? null) === null) {
                return $base + ['status' => 'unavailable', 'reason' => 'complete_declared_click_to_purchase_chain_required', 'credits' => []];
            }
        }
        $maps = [];
        foreach ($f['stages'] as $stage => $events) {
            $maps[$stage] = [];
            foreach ($events ?? [] as $event) {
                $maps[$stage][$event['event_id']] = $event;
            }
        }
        $credits = [];
        $count = BigRational::zero();
        $value = BigRational::zero();
        $valueComplete = true;
        $paths = [];
        foreach ($f['stages']['purchase'] as $purchase) {
            $at = $purchase;
            $path = ['purchase' => $purchase['event_id']];
            foreach (['qualified_lead', 'lead', 'landing', 'click'] as $stage) {
                $key = $at['parent_event_id'];
                if (! isset($maps[$stage][$key])) {
                    return $base + ['status' => 'unavailable', 'reason' => 'linked_parent_chain_missing', 'credits' => []];
                }$at = $maps[$stage][$key];
                $path[$stage] = $key;
            }
            $id = $at['event_id'];
            $credits[$id] ??= ['conversion_credit' => BigRational::zero(), 'value_credit' => BigRational::zero(), 'value_complete' => true];
            $credits[$id]['conversion_credit'] = $credits[$id]['conversion_credit']->plus(1);
            $count = $count->plus(1);
            if (! array_key_exists('value', $purchase) || $purchase['value'] === null) {
                $credits[$id]['value_complete'] = false;
                $valueComplete = false;
            } else {
                $v = ExactValue::of($purchase['value'])->rational();
                $credits[$id]['value_credit'] = $credits[$id]['value_credit']->plus($v);
                $value = $value->plus($v);
            }$paths[] = $path;
        }
        ksort($credits, SORT_STRING);
        $sumCount = BigRational::zero();
        $sumValue = BigRational::zero();
        $out = [];
        foreach ($credits as $id => $c) {
            $sumCount = $sumCount->plus($c['conversion_credit']);
            $sumValue = $sumValue->plus($c['value_credit']);
            $out[$id] = ['purchase_credit_exact' => ExactValue::fraction($c['conversion_credit']), 'declared_value_credit_exact' => $c['value_complete'] ? ExactValue::fraction($c['value_credit']) : null];
        }
        if (! $sumCount->isEqualTo($count) || ! $sumValue->isEqualTo($value)) {
            throw new \LogicException('linked_attribution_conservation_failed');
        }

        return $base + ['status' => 'computed_declared_single_click_credit', 'counting_unit' => 'purchase_events_not_unique_people', 'currency' => $f['currency'], 'purchase_events_exact' => ExactValue::fraction($count), 'declared_purchase_value_exact' => $valueComplete ? ExactValue::fraction($value) : null, 'credits' => $out, 'paths' => $paths, 'credit_conservation' => true, 'value_conservation' => $valueComplete ? true : null, 'limits' => ['Each supplied purchase is assigned to its one explicitly linked preceding click. This is declared linkage, not proof that no other touch occurred.', 'The input does not contain complete multi-touch histories. First-click/last-click/linear/time-decay/position comparisons and trained attribution are not inferred from a single parent chain.', 'Click keys are opaque source events, not verified platform or campaign identities, unique people, causal credit or deduplicated cross-source sales.', 'Unknown purchase values stay unknown. Reported credit is never merged into recorded advertising conversions, revenue or optimization response.']];
    }
}
