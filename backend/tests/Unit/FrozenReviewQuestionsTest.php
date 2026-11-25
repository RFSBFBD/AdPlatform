<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\FrozenReviewQuestions;
use PHPUnit\Framework\TestCase;

final class FrozenReviewQuestionsTest extends TestCase
{
    public function test_every_answer_is_an_unchanged_section_not_a_new_numeric_claim(): void
    {
        $e = new FrozenReviewQuestions;
        $r = ['snapshot_id' => 's', 'actual' => ['totals' => ['spend' => '9007199254740993.000000001']], 'consumers' => ['contribution_trace' => ['status' => 'traced']]];
        $a = $e->answer($r, 'actual');
        self::assertSame($r['actual']['totals'], $a['sections'][0]['value']);
        self::assertFalse($a['external_ai_called']);
        self::assertSame('unavailable', $e->answer($r, 'changes')['sections'][0]['status']);
        self::assertSame('unsupported_question', $e->answer($r, 'invent target probability')['status']);
        foreach (array_keys(FrozenReviewQuestions::ROUTES) as $intent) {
            self::assertSame('s', $e->answer($r, $intent)['snapshot_id']);
        }
    }
}
