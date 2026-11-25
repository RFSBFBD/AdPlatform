<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\Accounting\RecordedLedger;
use App\Services\Decision\FrozenAnomalyEvidence;
use PHPUnit\Framework\TestCase;

final class FrozenAnomalyEvidenceTest extends TestCase
{
    private function ledger(array $series): array
    {
        $scope = ['provider' => 'synthetic', 'account_id' => 'a', 'source_contract' => 'synthetic-v1', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'reporting_window' => '2026-11-01/2026-11-'.count($series), 'outcome_definition' => 'synthetic-credit', 'outcome_kind' => 'other_explicit', 'attribution' => 'synthetic', 'population' => 'a', 'conversion_count_kind' => 'fractional_credit', 'provenance_kind' => 'synthetic_fixture'];
        $rows = [];
        foreach ($series as $i => $value) {
            $rows[] = ['key' => (string) $i, 'variable_id' => 'a', 'scope' => $scope, 'source_ref' => 'synthetic:'.$i, 'reporting_date' => sprintf('2026-11-%02d', $i + 1), 'spend' => $value];
        }

        return (new RecordedLedger($scope, $rows))->jsonSerialize();
    }

    public function test_flags_resolve_to_exact_original_day_and_frozen_policy_without_probability(): void
    {
        $r = (new FrozenAnomalyEvidence)->build($this->ledger(['1', '2', '3', '4', '5', '6', '7', '8', '9', '100']));
        self::assertSame('computed_retrospective_screens', $r['status']);
        $s = $r['entities']['a']['spend'];
        self::assertSame('2026-11-10', $s['analysis']['iqr']['flags'][0]['date']);
        self::assertSame('100', $s['analysis']['iqr']['flags'][0]['operand_recorded_exact']);
        self::assertNull($r['false_alarm_probability']);
        self::assertSame('insufficient_history', $s['analysis']['change_point']['status']);
        self::assertSame('unavailable', $r['entities']['a']['conversions']['status']);
    }

    public function test_constant_data_degenerate_and_structural_split_have_no_causal_verdict(): void
    {
        $e = new FrozenAnomalyEvidence;
        $r = $e->build($this->ledger(array_fill(0, 12, '2')));
        foreach (['iqr', 'robust_z', 'ewma', 'change_point'] as $method) {
            self::assertSame('degenerate', $r['entities']['a']['spend']['analysis'][$method]['status']);
        }
        $r = $e->build($this->ledger(['1', '2', '1', '2', '1', '2', '20', '21', '20', '21', '20', '21']));
        self::assertTrue($r['entities']['a']['spend']['analysis']['change_point']['detected']);
        self::assertSame('2026-11-07', $r['entities']['a']['spend']['analysis']['change_point']['date']);
        self::assertNull($r['causal_attribution']);
    }

    public function test_real_source_missing_calendar_and_numeric_loss_abstain_without_dropping_days(): void
    {
        $e = new FrozenAnomalyEvidence;
        $l = $this->ledger(array_fill(0, 10, '1'));
        $l['scope']['provenance_kind'] = 'recorded_source';
        self::assertSame('source_population_not_qualified', $e->build($l)['reason']);
        $l = $this->ledger(array_fill(0, 10, '1'));
        array_pop($l['rows']);
        self::assertSame('complete_declared_supplied_calendar_required', $e->build($l)['reason']);
        $l = $this->ledger(array_fill(0, 10, '1000000000001'));
        self::assertSame('model_numeric_range_or_operand_conversion_loss', $e->build($l)['entities']['a']['spend']['reason']);
    }
}
