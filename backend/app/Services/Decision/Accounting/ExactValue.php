<?php

declare(strict_types=1);

namespace App\Services\Decision\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Exact recorded operands. No float enters this boundary, including whole-valued floats. */
final class ExactValue implements \JsonSerializable
{
    public const POLICY = 'exact-recorded-v1';

    // Resource bounds are explicit rejection limits, not rounding scales or monetary quanta.
    public const MAX_DIGITS = 4096;

    private function __construct(public readonly string $lexeme, private readonly BigDecimal $decimal) {}

    public static function of(mixed $value, bool $integral = false, bool $allowNegative = false): self
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('exact_operand_requires_original_decimal_string_or_integer');
        }
        $token = (string) $value;
        if (strlen($token) > self::MAX_DIGITS + 2 || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $token)) {
            throw new InvalidArgumentException('unsupported_exact_decimal_lexeme_or_resource_limit');
        }
        $digits = strlen(str_replace(['-', '.'], '', $token));
        if ($digits > self::MAX_DIGITS) {
            throw new InvalidArgumentException('exact_decimal_resource_limit');
        }
        $n = BigDecimal::of($token);
        if (! $allowNegative && $n->isLessThan(0)) {
            throw new InvalidArgumentException('negative_operand_not_admitted');
        }
        if ($integral && ! $n->isEqualTo($n->toBigRational()->getNumerator()->quotient($n->toBigRational()->getDenominator()))) {
            throw new InvalidArgumentException('fractional_integral_count');
        }

        return new self($token, $n);
    }

    public function decimal(): BigDecimal
    {
        return $this->decimal;
    }

    public function rational(): BigRational
    {
        return $this->decimal->toBigRational();
    }

    public function jsonSerialize(): array
    {
        return ['lexeme' => $this->lexeme, 'decimal' => (string) $this->decimal,
            'source_scale' => $this->decimal->getScale(), 'precision' => self::POLICY];
    }

    public static function fraction(BigRational $number): array
    {
        return ['numerator' => (string) $number->getNumerator(), 'denominator' => (string) $number->getDenominator()];
    }
}
