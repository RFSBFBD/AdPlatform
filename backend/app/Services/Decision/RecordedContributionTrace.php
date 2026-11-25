<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;
use Brick\Math\BigDecimal;

/** Exact additive-contribution explanation for supplied rows, not proof of source completeness. */
final class RecordedContributionTrace
{
    public function build(array $ledger): array
    {
        $sums = array_fill_keys(RecordedLedger::METRICS, BigDecimal::zero());
        $missing = [];
        $rows = [];
        foreach ($ledger['rows'] as $key => $row) {
            $metrics = [];
            foreach (RecordedLedger::METRICS as $metric) {
                $operand = $row['metrics'][$metric]['decimal'];
                $metrics[$metric] = $operand;
                if ($operand === null) {
                    $missing[$metric] = true;
                } else {
                    $sums[$metric] = $sums[$metric]->plus($operand);
                }
            }
            $rows[] = ['row_key' => $key, 'entity' => $row['variable_id'], 'date' => $row['reporting_date'], 'source_ref' => $row['source_ref'], 'operands' => $metrics];
        }
        $totals = [];
        $checks = [];
        foreach ($sums as $metric => $sum) {
            $totals[$metric] = isset($missing[$metric]) ? null : (string) $sum;
            $recorded = $ledger['totals'][$metric];
            $checks[$metric] = $recorded === null ? $totals[$metric] === null : ($totals[$metric] !== null && BigDecimal::of($recorded)->isEqualTo($sum));
        }

        return ['status' => in_array(false, $checks, true) ? 'rejected_conservation_mismatch' : 'traced_supplied_contributions', 'snapshot_id' => $ledger['snapshot_id'],
            'rows' => $rows, 'recomputed_totals' => $totals, 'matches_recorded_totals' => $checks, 'source_lineage' => $ledger['scope']['source_lineage'] ?? null, 'native_extensions' => $ledger['scope']['native_extensions'] ?? null,
            'limits' => 'Only supplied additive row contributions. Missing operands propagate unknown. Missing rows are not zero. Ratios are recomputed from summed operands, not added. Source refs identify retained evidence, not provider reconciliation or verified inventory.'];
    }
}
