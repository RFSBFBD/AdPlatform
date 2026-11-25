<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Services\Decision\Accounting\RecordedLedger;
use InvalidArgumentException;

/** Closed explanations from computed six-block evidence. No model call or free-form numeric claims. */
final class FrozenEvidenceExplanation
{
    public const BLOCKS = ['actual_metrics', 'forecast', 'optimization', 'diagnostics', 'confidence', 'data_quality'];

    public function build(string $snapshot, array $blocks): array
    {
        if (count($blocks) !== count(self::BLOCKS) || array_diff(self::BLOCKS, array_keys($blocks))) {
            throw new InvalidArgumentException('six_computed_evidence_blocks_required');
        }
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['snapshot_id'] ?? null) !== $snapshot) {
                throw new InvalidArgumentException('explanation_snapshot_mismatch');
            }
        }
        $facts = [];
        $add = function (string $block, array $path, string $label, string $type, string $precision, ?string $unit = null) use (&$facts, $blocks, $snapshot): void {
            $value = $blocks[$block];
            foreach ($path as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    return;
                }
                $value = $value[$key];
            }
            $pointer = '/'.implode('/', array_map(fn ($key) => str_replace(['~', '/'], ['~0', '~1'], (string) $key), [$block, ...$path]));
            $facts[] = ['id' => hash('sha256', $snapshot.$pointer), 'snapshot_id' => $snapshot, 'block' => $block, 'pointer' => $pointer,
                'label' => $label, 'type' => $type, 'precision' => $precision, 'unit' => $unit, 'status' => $value === null ? 'unavailable' : 'computed', 'value' => $value];
        };
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'conversion_value'] as $metric) {
            $unit = match ($metric) {
                'spend' => $blocks['actual_metrics']['currency'], 'conversion_value' => 'declared_outcome_value',
                'conversions' => 'declared_outcome_count_or_credit', default => 'count'
            };
            $add('actual_metrics', ['totals', $metric], 'Recorded '.$metric, 'ACTUAL', 'exact_recorded_decimal_or_integer', $unit);
        }
        $add('forecast', ['status'], 'Independent forecast state', 'EXPECTED', 'state');
        $add('forecast', ['calibration'], 'Forecast calibration', 'EXPECTED', 'state');
        $add('optimization', ['status'], 'Model scenario state', 'SCENARIO', 'state');
        $add('optimization', ['objective'], 'Explicit objective', 'SCENARIO', 'state');
        $add('optimization', ['target_optimization'], 'Explicit target policy', 'SCENARIO', 'state');
        foreach ($blocks['optimization']['scenarios'] ?? [] as $index => $scenario) {
            $name = $scenario['name'];
            $add('optimization', ['scenarios', $index, 'check_status'], $name.' independent check', 'SCENARIO', 'state');
            if (($scenario['check_status'] ?? null) === 'checked') {
                $add('optimization', ['scenarios', $index, 'spend_exact'], $name.' modeled spend', 'SCENARIO', 'exact_proposed_allocation_sum', $blocks['actual_metrics']['currency']);
                $add('optimization', ['scenarios', $index, 'response_approximate'], $name.' modeled response', 'SCENARIO', 'approximate_model_response', $blocks['optimization']['response_unit'] ?? 'declared_problem_response');
            }
        }
        $add('diagnostics', ['status'], 'Diagnostic interpretation boundary', 'DESCRIPTIVE', 'state');
        foreach ($blocks['diagnostics']['entities'] ?? [] as $id => $diagnostic) {
            $add('diagnostics', ['entities', $id, 'status'], 'Entity '.$id.' diagnostic state', 'DESCRIPTIVE', 'state');
            $add('diagnostics', ['entities', $id, 'health'], 'Entity '.$id.' health', 'DESCRIPTIVE', 'unvalidated_health_state');
        }
        $add('confidence', ['allocation_probability'], 'Allocation success probability', 'UNAVAILABLE', 'no_calibrated_probability');
        $add('confidence', ['forecast_probability'], 'Forecast success probability', 'UNAVAILABLE', 'no_calibrated_probability');
        $add('data_quality', ['provenance_kind'], 'Evidence provenance, synthetic is not a real-source admission', 'EVIDENCE', 'state');
        $add('data_quality', ['calendar_population_complete'], 'Supplied calendar complete', 'EVIDENCE', 'boolean_not_probability');
        $add('data_quality', ['source_population_qualified'], 'Source population qualified', 'EVIDENCE', 'boolean_not_probability');

        return ['version' => 'closed-six-block-explanation-v1', 'snapshot_id' => $snapshot, 'status' => 'computed_reference_facts', 'facts' => $facts,
            'source' => 'consumers.analyst_input', 'external_ai_called' => false, 'operational_approval' => false,
            'limits' => ['Every displayed value resolves to a pointer in the computed six-block input; labels do not authorize effects.',
                'Exact recorded arithmetic is not provider truth. Model responses are approximate scenarios, never actual delivery.',
                'These fixed reference facts do not implement open-ended AI answers or reports. Unknown probabilities remain unavailable.']];
    }

    public function verify(array $explanation, array $blocks): bool
    {
        // Full canonical regeneration rejects invented labels, precision/type upgrades, missing facts and changed operands.
        return RecordedLedger::canonical($explanation)
            === RecordedLedger::canonical($this->build($explanation['snapshot_id'] ?? '', $blocks));
    }
}
