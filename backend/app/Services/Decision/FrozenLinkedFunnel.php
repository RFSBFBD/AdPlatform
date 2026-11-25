<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Explicit linked source events, not conversion-name inference or unique-person identity resolution. */
final class FrozenLinkedFunnel
{
    public const STAGES = ['impression', 'click', 'landing', 'lead', 'qualified_lead', 'purchase'];

    public function aggregate(array $scope, array $input): array
    {
        if (array_diff(array_keys($input), ['version', 'evidence_id', 'currency', 'timezone', 'reporting_window', 'population', 'counting_unit', 'stages']) || ($input['version'] ?? null) !== 'linked-funnel-events-v1' || ($input['counting_unit'] ?? null) !== 'source_events_not_unique_people' || ! is_string($input['evidence_id'] ?? null) || $input['evidence_id'] === '' || ! is_array($input['stages'] ?? null) || array_diff(array_keys($input['stages']), self::STAGES) || array_diff(self::STAGES, array_keys($input['stages']))) {
            throw new InvalidArgumentException('explicit_linked_funnel_contract_required');
        }
        foreach (['currency', 'timezone', 'reporting_window', 'population'] as $k) {
            if (($input[$k] ?? null) !== $scope[$k]) {
                throw new InvalidArgumentException('funnel_scope_mismatch:'.$k);
            }
        }
        $window = explode('/', $scope['reporting_window']);
        $seen = [];
        $prior = null;
        $priorCount = null;
        $rows = [];
        $count = 0;
        $revenue = null;
        foreach (self::STAGES as $index => $stage) {
            $events = $input['stages'][$stage];
            if ($events === null) {
                $rows[$stage] = ['status' => 'unavailable', 'count_exact' => null, 'reason' => 'source_stage_not_supplied'];
                $prior = null;
                $priorCount = null;

                continue;
            }
            if (! is_array($events) || ! array_is_list($events) || ($count += count($events)) > 10000) {
                throw new InvalidArgumentException('funnel_event_list_or_resource_limit');
            }
            if ($index > 0 && $prior === null) {
                throw new InvalidArgumentException('funnel_preceding_stage_unknown');
            }
            $current = [];
            $linked = [];
            $sum = BigDecimal::zero();
            $missingValue = false;
            foreach ($events as $e) {
                $allowed = $stage === 'purchase' ? ['event_id', 'parent_event_id', 'occurred_at', 'value'] : ['event_id', 'parent_event_id', 'occurred_at'];
                if (! is_array($e) || array_diff(array_keys($e), $allowed) || ! is_string($e['event_id'] ?? null) || ! preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $e['event_id']) || isset($seen[$e['event_id']])) {
                    throw new InvalidArgumentException('unique_nonpersonal_funnel_event_key_required');
                }
                $time = $e['occurred_at'] ?? null;
                if (! is_string($time) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $time)) {
                    throw new InvalidArgumentException('explicit_funnel_event_time_required');
                }
                if (! str_ends_with($time, 'Z')) {
                    $offset = substr($time, -6);
                    $hours = (int) substr($offset, 1, 2);
                    $minutes = (int) substr($offset, 4, 2);
                    if ($hours > 14 || $minutes > 59 || ($hours === 14 && $minutes !== 0)) {
                        throw new InvalidArgumentException('invalid_funnel_time_offset');
                    }
                }
                try {
                    $dt = new \DateTimeImmutable($time);
                } catch (\Throwable) {
                    throw new InvalidArgumentException('invalid_funnel_time');
                }$errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                    throw new InvalidArgumentException('invalid_funnel_time');
                }$day = $dt->setTimezone(new \DateTimeZone($scope['timezone']))->format('Y-m-d');
                if (count($window) !== 2 || $day < $window[0] || $day > $window[1]) {
                    throw new InvalidArgumentException('funnel_event_outside_window');
                }
                $parent = $e['parent_event_id'] ?? null;
                if ($index === 0) {
                    if ($parent !== null) {
                        throw new InvalidArgumentException('impression_must_not_have_parent');
                    }
                } else {
                    if (! is_string($parent) || ! isset($prior[$parent]) || $dt->getTimestamp() < $prior[$parent]) {
                        throw new InvalidArgumentException('funnel_missing_or_out_of_order_parent');
                    }$linked[$parent] = true;
                }
                $seen[$e['event_id']] = true;
                $current[$e['event_id']] = $dt->getTimestamp();
                if ($stage === 'purchase') {
                    if (! array_key_exists('value', $e) || $e['value'] === null) {
                        $missingValue = true;
                    } else {
                        $sum = $sum->plus(ExactValue::of($e['value'])->decimal());
                    }
                }
            }
            $transition = $index === 0 ? null : ['previous_stage' => self::STAGES[$index - 1], 'previous_event_count_exact' => (string) $priorCount, 'parents_with_child_exact' => (string) count($linked), 'parents_without_child_exact' => (string) ($priorCount - count($linked)), 'parent_coverage_fraction_exact' => $priorCount === 0 ? null : ExactValue::fraction(BigRational::of(count($linked))->dividedBy($priorCount)), 'child_events_per_parent_may_exceed_one' => true];
            $rows[$stage] = ['status' => 'declared_linked_events', 'count_exact' => (string) count($events), 'transition' => $transition];
            $prior = $current;
            $priorCount = count($events);
            if ($stage === 'purchase') {
                $revenue = $missingValue ? null : (string) $sum;
            }
        }
        $qualified = ($scope['provenance_kind'] ?? null) === 'synthetic_fixture';

        return ['version' => 'frozen-linked-funnel-summary-v1', 'status' => $qualified ? 'synthetic_linked_event_evidence' : 'declared_linkage_not_provider_verified', 'evidence_id' => $input['evidence_id'], 'scope' => array_intersect_key($scope, array_flip(['currency', 'timezone', 'reporting_window', 'population'])), 'counting_unit' => 'source_events_not_unique_people', 'source_population_qualified' => $qualified, 'stages' => $rows, 'revenue' => ['status' => $revenue === null ? 'unavailable' : 'declared_purchase_value', 'value_exact' => $revenue, 'currency' => $scope['currency'], 'profit' => null, 'refund_reconciliation' => false],
            'limits' => ['Funnel events are a separate supplied extension; matching scope labels do not reconcile their counts to advertising delivery totals. No complete source inventory claim.', 'This is only the explicitly supplied linked event population, not provider inventory or unique people. No identity matching or attribution is inferred.', 'Transition coverage counts distinct parent events with a linked child, not child/parent conversion rate. Multiple child events per parent are allowed.', 'All included events must fall inside the same declared window. Cross-window journey completeness and delayed outcomes are not established. Missing stages stay unknown.', 'Purchase values are source-declared, not verified sales, profit or net revenue. No causal bottleneck or campaign-health verdict.']];
    }

    public function build(array $ledger): array
    {
        if (! isset($ledger['linked_funnel'])) {
            return ['status' => 'unavailable', 'snapshot_id' => $ledger['snapshot_id'], 'reason' => 'explicit_linked_stage_events_not_supplied', 'stages' => [], 'limits' => ['Recorded impressions, clicks and one conversion definition are not sufficient to infer a linked full funnel.']];
        }

        return ['snapshot_id' => $ledger['snapshot_id']] + $this->aggregate($ledger['scope'], $ledger['linked_funnel']);
    }
}
