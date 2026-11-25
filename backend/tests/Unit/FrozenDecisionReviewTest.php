<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\FrozenDecisionReview;
use PHPUnit\Framework\TestCase;

final class FrozenDecisionReviewTest extends TestCase
{
    private function fixture(): array
    {
        return ['snapshot_id' => 'synthetic', 'problem_id' => 'p', 'actual' => ['entity_totals' => ['a/b~' => ['spend' => '10.000001']]], 'problem' => ['currency' => 'EGP', 'response_unit' => 'credit', 'objective' => 'MAXIMIZE_CONVERSIONS', 'variables' => [['id' => 'a/b~', 'platform' => 'synthetic', 'entity_level' => 'campaign', 'entity_id' => 'a']]], 'model_snapshot' => ['a/b~' => ['curve' => ['name' => 'linear'], 'model_version' => 'synthetic-v1']],
            'scenarios' => ['current' => ['allocation' => ['a/b~' => '10.000001'], 'check' => ['status' => 'checked', 'spend_exact' => '10.000001', 'response_approximate' => 20., 'parts' => ['a/b~' => ['response_approximate' => 20.]]]], 'optimized' => ['allocation' => ['a/b~' => '15.000001'], 'check' => ['status' => 'checked', 'spend_exact' => '15.000001', 'response_approximate' => 30., 'parts' => ['a/b~' => ['response_approximate' => 30.]]]]]];
    }

    public function test_structured_evidence_preserves_types_precision_unknown_confidence_and_simulation_only(): void
    {
        $r = (new FrozenDecisionReview)->build($this->fixture());
        $item = $r['items'][0];
        self::assertSame('computed_simulation_review', $r['status']);
        self::assertSame('INSUFFICIENT_DATA', $r['decision_state']);
        self::assertSame('5.000000', $item['budget_change_exact']);
        self::assertSame(['numerator' => '5000000', 'denominator' => '10000001'], $item['budget_change_fraction_exact']);
        self::assertSame('ACTUAL', $item['observed']['type']);
        self::assertSame('SCENARIO', $item['baseline']['type']);
        self::assertNull($item['confidence']['probability']);
        self::assertSame('SIMULATION_ONLY', $item['action']['type']);
        self::assertSame('/scenarios/optimized/allocation/a~1b~0', $item['evidence']['allocation_pointer']);
        self::assertSame(10., $item['expected_impact']['change_approximate']);
    }

    public function test_rejected_baselines_withhold_items_and_identical_allocations_do_not_invent_changes(): void
    {
        $e = new FrozenDecisionReview;
        $r = $this->fixture();
        $r['scenarios']['current']['check']['status'] = 'rejected';
        self::assertSame([], $e->build($r)['items']);
        $r = $this->fixture();
        $r['scenarios']['optimized']['allocation'] = $r['scenarios']['current']['allocation'];
        self::assertSame(0, $e->build($r)['candidate_count']);
        $r = $this->fixture();
        $r['scenarios']['current']['allocation']['a/b~'] = '0';
        self::assertNull($e->build($r)['items'][0]['budget_change_fraction_exact']);
    }
}
