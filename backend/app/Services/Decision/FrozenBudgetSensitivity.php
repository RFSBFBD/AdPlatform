<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\ExactValue;
use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Explicit budget-cap shocks, frozen models. Fixed allocation stress is separate from reoptimization. */
final class FrozenBudgetSensitivity
{
    public function analyze(array $result, array $multipliers): array
    {
        if (($result['status'] ?? null) !== 'checked_reference_scenario' || $multipliers === [] || ! array_is_list($multipliers) || count($multipliers) > 5) {
            throw new InvalidArgumentException('checked_snapshot_and_one_to_five_explicit_shocks_required');
        }
        $sealed = $result;
        unset($sealed['audit_hash']);
        if (! is_string($result['audit_hash'] ?? null) || ! hash_equals($result['audit_hash'], hash('sha256', RecordedLedger::canonical($sealed)))) {
            throw new InvalidArgumentException('sensitivity_archive_checksum_mismatch');
        }
        $models = [];
        foreach ($result['model_snapshot'] as $id => $model) {
            $model['curve'] = (new FrozenModelPlotEvidence)->restore($model['curve']);
            $models[$id] = $model;
        }
        $ledgerRows = [];
        foreach ($result['actual']['rows'] as $key => $row) {
            $r = ['key' => $key, 'variable_id' => $row['variable_id'], 'source_ref' => $row['source_ref'], 'reporting_date' => $row['reporting_date'], 'scope' => $result['actual']['scope']];
            foreach ($row['metrics'] as $metric => $value) {
                $r[$metric] = $value['lexeme'];
            }$ledgerRows[] = $r;
        }
        $ledger = isset($result['actual']['source_envelope_json']) ? RecordedLedger::fromRecordedJson($result['actual']['scope'], $result['actual']['source_envelope_json'], $result['actual']['linked_funnel'] ?? null) : new RecordedLedger($result['actual']['scope'], $ledgerRows, null, $result['actual']['linked_funnel'] ?? null);
        if ($ledger->jsonSerialize()['snapshot_id'] !== $result['snapshot_id']) {
            throw new InvalidArgumentException('sensitivity_source_snapshot_mismatch');
        }
        $baseSpec = $result['problem'];
        unset($baseSpec['problem_version'], $baseSpec['forecast_horizon_days'], $baseSpec['budget_shock_multipliers']);
        $baseAllocation = $result['scenarios']['optimized']['allocation'];
        $scenarios = [];
        $allChecked = true;
        foreach ($multipliers as $scale) {
            $factor = ExactValue::of($scale)->decimal();
            if ($factor->isZero() || $factor->isGreaterThan('10')) {
                throw new InvalidArgumentException('positive_budget_scale_at_most_ten_required');
            }
            $spec = $baseSpec;
            $spec['budget_cap'] = (string) ExactValue::of($baseSpec['budget_cap'])->decimal()->multipliedBy($factor);
            try {
                $problem = new DecisionProblem($spec);
            } catch (InvalidArgumentException $e) {
                $scenarios[] = ['scale' => $scale, 'status' => 'constraints_infeasible_or_unsupported', 'reason' => $e->getMessage()];
                $allChecked = false;

                continue;
            }
            $fixed = (new IndependentAllocationCheck)->check($problem, $models, $baseAllocation, $spec['allocation_mode'] === 'finite_menu');
            $rerun = (new IntegratedDecisionPipeline)->run($ledger, $problem, $models);
            $optimized = $rerun['scenarios']['optimized'] ?? null;
            $changes = [];
            $l1 = BigDecimal::zero();
            if ($optimized !== null) {
                foreach ($baseAllocation as $id => $before) {
                    $after = $optimized['allocation'][$id];
                    $delta = ExactValue::of($after)->decimal()->minus($before);
                    $changes[$id] = (string) $delta;
                    $l1 = $l1->plus($delta->abs());
                }
            } else {
                $allChecked = false;
            }
            $scenarios[] = ['scale' => $scale, 'budget_cap_exact' => $spec['budget_cap'], 'status' => $rerun['status'], 'fixed_allocation_check' => $fixed,
                'reoptimized' => $optimized, 'allocation_changes_exact' => $changes, 'allocation_l1_change_exact' => $optimized === null ? null : (string) $l1,
                'problem_id' => $rerun['problem_id'], 'route' => $rerun['route'], 'certificate' => $rerun['certificate'] ?? null];
        }

        return ['status' => $allChecked ? 'evaluated_budget_shocks' : 'not_all_shocks_accepted', 'snapshot_id' => $result['snapshot_id'], 'type' => 'SCENARIO', 'scenarios' => $scenarios,
            'primitive_rate_shocks' => 'not_inferred_from_spend_response_curve', 'stability_probability' => null, 'stability_classification' => null, 'model_refit' => false,
            'limits' => ['Only the explicit budget CAP changes, minima/entity/group/target constraints stay fixed.', 'L1 allocation movement is deterministic sensitivity, not confidence or a calibrated stability score.', 'Finite-menu restrictions and numerical LP certificates remain their original scope.', 'No causal or future-outcome guarantee. No campaign effects.']];
    }
}
