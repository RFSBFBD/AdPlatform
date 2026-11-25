<?php

namespace Tests\Unit;

use App\Services\Decision\Analyst\GroundedAnalystQa;
use App\Services\Decision\Analyst\ScriptedAnalystProvider;
use PHPUnit\Framework\TestCase;

final class GroundedAnalystQaTest extends TestCase
{
    private function evidence(): array
    {
        return ['snapshot_id' => 'snap-1',
            'actual' => ['totals' => ['spend' => '1234.50', 'clicks' => 980, 'impressions' => 78400, 'by_campaign' => ['111' => ['cpa' => '12.5', 'currency' => 'EGP'], '222' => ['cpa' => '9.75', 'currency' => 'EGP'], '333' => ['cpa' => '9.75', 'currency' => 'USD'], '444' => ['roas' => '3']]]],
            'forecast' => ['next_week_spend' => '1500.00'],
            'consumers' => ['diagnostics' => ['a' => ['note' => 'اسم الحملة: ربيع'], 'b' => ['note' => 'x']]]];
    }

    private function ask(?array $reply, ?ScriptedAnalystProvider &$p = null, string $q = 'ما الإنفاق؟'): array
    {
        $p = new ScriptedAnalystProvider($reply);

        return (new GroundedAnalystQa($p))->ask($this->evidence(), $q);
    }

    public function test_value_claim_text_is_written_by_server_in_both_languages_from_the_evidence(): void
    {
        $r = $this->ask(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']]]);
        $this->assertSame('evidence_constrained_answer', $r['status']);
        $this->assertSame('actual / totals / spend: 1234.50 [ACTUAL]', $r['claims'][0]['text']);
        $this->assertStringContainsString('[فعلي]', $r['claims'][0]['text_ar']);
        $this->assertFalse($r['external_ai_called']);
        $this->assertStringContainsString('NOT verified', $r['limits']);
    }

    public function test_provider_cannot_inject_text_numbers_or_extra_fields(): void
    {
        foreach ([
            [['type' => 'value', 'pointer' => '/actual/totals/spend', 'text' => 'Spend fell 50% so raise budget']],
            [['type' => 'value', 'pointer' => '/actual/totals/spend', 'text_ar' => 'ارفع الميزانية']],
            [['type' => 'free_text', 'text' => 'ارفع الميزانية 100%']],
        ] as $claims) {
            $r = $this->ask(['claims' => $claims]);
            $this->assertSame('rejected_outside_evidence', $r['status']);
            $this->assertArrayNotHasKey('claims', $r);
        }
        $this->assertSame('rejected_outside_evidence', $this->ask(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']], 'summary' => 'x'])['status']);
    }

    public function test_wrong_direction_is_rejected_and_right_direction_is_rendered_exactly(): void
    {
        $bad = $this->ask(['claims' => [['type' => 'compare', 'a' => '/actual/totals/by_campaign/111/cpa', 'b' => '/actual/totals/by_campaign/222/cpa', 'relation' => 'less']]]);
        $this->assertSame(['claim_0:relation_false'], $bad['reasons']);
        $ok = $this->ask(['claims' => [['type' => 'compare', 'a' => '/actual/totals/by_campaign/111/cpa', 'b' => '/actual/totals/by_campaign/222/cpa', 'relation' => 'greater']]]);
        $this->assertSame('actual / totals / by_campaign / 111 / cpa (12.5) is greater than actual / totals / by_campaign / 222 / cpa (9.75) [ACTUAL]', $ok['claims'][0]['text']);
        $this->assertStringContainsString('أكبر من', $ok['claims'][0]['text_ar']);
    }

    public function test_compare_payload_matches_the_fixture_the_frontend_renders(): void
    {
        $r = $this->ask(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend'], ['type' => 'compare', 'a' => '/actual/totals/by_campaign/111/cpa', 'b' => '/actual/totals/by_campaign/222/cpa', 'relation' => 'greater']]]);
        $json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $path = dirname(__DIR__).'/fixtures/assist-compare-response.json';
        if (getenv('UPDATE_FIXTURES') === '1') {
            file_put_contents($path, $json);
            file_put_contents(dirname(__DIR__, 3).'/frontend/src/app/features/analytics-lab/testdata/assist-compare-response.json', $json);
        }
        // Only line endings are normalised (a Windows checkout may rewrite them). Content must match exactly.
        $read = fn (string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));
        $this->assertSame($json, $read($path));
        $this->assertSame($json, $read(dirname(__DIR__, 3).'/frontend/src/app/features/analytics-lab/testdata/assist-compare-response.json'));
    }

    public function test_comparison_requires_same_metric_scope_and_currency(): void
    {
        $cmp = fn ($a, $b) => $this->ask(['claims' => [['type' => 'compare', 'a' => $a, 'b' => $b, 'relation' => 'greater']]])['reasons'] ?? 'accepted';
        $this->assertSame(['claim_0:different_metric_or_depth'], $cmp('/actual/totals/spend', '/actual/totals/clicks'));
        $this->assertSame(['claim_0:different_metric_or_depth'], $cmp('/actual/totals/by_campaign/111/cpa', '/actual/totals/by_campaign/444/roas'));
        $this->assertSame(['claim_0:different_currency_or_scope'], $cmp('/actual/totals/by_campaign/111/cpa', '/actual/totals/by_campaign/333/cpa'));
    }

    public function test_answer_states_relevance_is_unverified_and_not_a_validated_answer(): void
    {
        $r = $this->ask(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']]]);
        $this->assertFalse($r['relevance_verified']);
        $this->assertFalse($r['validated_answer']);
    }

    public function test_cannot_compare_actual_with_expected_or_non_numeric_text(): void
    {
        $this->assertSame(['claim_0:mixed_evidence_types'], $this->ask(['claims' => [['type' => 'compare', 'a' => '/actual/totals/spend', 'b' => '/forecast/next_week_spend', 'relation' => 'less']]])['reasons']);
        $this->assertSame(['claim_0:non_numeric_comparison'], $this->ask(['claims' => [['type' => 'compare', 'a' => '/consumers/diagnostics/a/note', 'b' => '/consumers/diagnostics/b/note', 'relation' => 'equal']]])['reasons']);
    }

    public function test_wrong_entity_or_metric_cannot_be_asserted_only_pointers_selected_are_shown_with_their_own_labels(): void
    {
        // The model "answers" a question about clicks by picking spend: it is shown labelled as spend, never relabelled.
        $r = $this->ask(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']]], $p, 'كم عدد النقرات؟ how many clicks?');
        $this->assertSame('evidence_constrained_answer', $r['status']);
        $this->assertStringStartsWith('actual / totals / spend:', $r['claims'][0]['text']);
        $this->assertStringContainsString('NOT verified', $r['limits']);
        $this->assertStringContainsString('لم يُتحقق', $r['limits_ar']);
    }

    public function test_rejects_unknown_pointers_bad_shapes_and_too_many_claims(): void
    {
        foreach ([null, [], ['claims' => []], ['claims' => 'x'], ['claims' => [['type' => 'value', 'pointer' => '/secret']]],
            ['claims' => array_fill(0, 9, ['type' => 'value', 'pointer' => '/actual/totals/spend'])]] as $reply) {
            $this->assertSame('rejected_outside_evidence', $this->ask($reply)['status']);
        }
    }

    public function test_provider_only_receives_route_evidence_leaves_and_empty_question_never_calls_it(): void
    {
        $p = new ScriptedAnalystProvider(['claims' => [['type' => 'value', 'pointer' => '/actual/totals/spend']]]);
        (new GroundedAnalystQa($p))->ask($this->evidence() + ['secret' => 'x'], 'q');
        $this->assertNotContains('/secret', array_column($p->requests[0]['evidence'], 'pointer'));
        $q = new ScriptedAnalystProvider(['claims' => []]);
        (new GroundedAnalystQa($q))->ask($this->evidence(), '  ');
        $this->assertSame([], $q->requests);
    }
}
