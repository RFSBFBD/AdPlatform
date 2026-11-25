<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Decision\Accounting\RecordedLedger;
use PHPUnit\Framework\TestCase;

final class RecordedCanonicalTest extends TestCase
{
    public function test_numeric_zero_roundtrip_does_not_rewrite_recorded_strings_or_large_operands(): void
    {
        $v = ['signed_string' => '-0.000', 'decimal_string' => '9007199254740993.123456789', 'zero_float' => -0.0, 'positive_zero' => 0.0, 'tiny' => 1e-100, 'large' => 1e100, 'integer' => PHP_INT_MAX];
        $canonical = RecordedLedger::canonical($v);
        self::assertSame($canonical, RecordedLedger::canonical(json_decode($canonical, true, 128, JSON_THROW_ON_ERROR)));
        $decoded = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
        self::assertSame('-0.000', $decoded['signed_string']);
        self::assertSame('9007199254740993.123456789', $decoded['decimal_string']);
        self::assertSame(0, $decoded['zero_float']);
        self::assertSame(0, $decoded['positive_zero']);
        self::assertSame(1e-100, $decoded['tiny']);
        self::assertSame(1e100, $decoded['large']);
    }
}
