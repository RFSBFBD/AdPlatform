<?php

declare(strict_types=1);

namespace App\Services\Decision\Accounting;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use InvalidArgumentException;

/** Exact rational value of an IEEE754 binary64 bit pattern, for honest conversion-loss checks. */
final class Binary64Boundary
{
    public static function rational(float $value): BigRational
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('nonfinite_binary64');
        }
        $bits = unpack('Nhi/Nlo', pack('E', $value));
        $negative = ($bits['hi'] & 0x80000000) !== 0;
        $exponent = ($bits['hi'] >> 20) & 0x7FF;
        $fraction = BigInteger::of($bits['hi'] & 0xFFFFF)->multipliedBy('4294967296')->plus($bits['lo']);
        if ($exponent === 0) {
            $power = -1074;
        } else {
            $fraction = $fraction->plus('4503599627370496');
            $power = $exponent - 1075;
        }
        if ($negative) {
            $fraction = $fraction->negated();
        }

        return $power >= 0 ? BigRational::of($fraction->multipliedBy(BigInteger::of(2)->power($power)))
            : BigRational::ofFraction($fraction, BigInteger::of(2)->power(-$power));
    }

    public static function loss(string $exact, float $converted): BigRational
    {
        return self::rational($converted)->minus($exact)->abs();
    }
}
