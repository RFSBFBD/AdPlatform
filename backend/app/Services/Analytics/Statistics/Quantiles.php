<?php

namespace App\Services\Analytics\Statistics;

/**
 * Statistical quantile functions, implemented deterministically in pure PHP.
 *
 * Student-t quantiles: Cornish-Fisher initial guess refined by bisection on the
 * exact CDF (regularized incomplete beta via continued fraction, Numerical
 * Recipes betacf). Validated against R reference values in tests to 1e-6.
 */
final class Quantiles
{
    /**
     * Inverse of the standard normal CDF (Acklam's rational approximation).
     */
    public static function normalQuantile(float $p): float
    {
        if ($p <= 0.0 || $p >= 1.0) {
            throw new \InvalidArgumentException('p must be in (0,1).');
        }
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $pLow = 0.02425;
        $pHigh = 1 - $pLow;

        if ($p < $pLow) {
            $q = sqrt(-2 * log($p));
            return (((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
                (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1));
        }
        if ($p <= $pHigh) {
            $q = $p - 0.5;
            $r = $q * $q;
            return (((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q) /
                (((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1));
        }
        $q = sqrt(-2 * log(1 - $p));
        return -(((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
            (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1));
    }

    /** Regularized incomplete beta I_x(a,b), continued fraction (NR 6.4). */
    public static function regularizedBeta(float $x, float $a, float $b): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }
        $lnFront = self::lgamma($a + $b) - self::lgamma($a) - self::lgamma($b)
            + $a * log($x) + $b * log(1 - $x);
        $front = exp($lnFront);
        if ($x < ($a + 1) / ($a + $b + 2)) {
            return $front * self::betaCf($x, $a, $b) / $a;
        }
        return 1 - $front * self::betaCf(1 - $x, $b, $a) / $b;
    }

    private static function betaCf(float $x, float $a, float $b): float
    {
        $maxIter = 200;
        $eps = 3e-14;
        $fpmin = 1e-300;
        $qab = $a + $b;
        $qap = $a + 1;
        $qam = $a - 1;
        $c = 1.0;
        $d = 1 - $qab * $x / $qap;
        if (abs($d) < $fpmin) {
            $d = $fpmin;
        }
        $d = 1 / $d;
        $h = $d;
        for ($m = 1; $m <= $maxIter; $m++) {
            $m2 = 2 * $m;
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1 + $aa * $d;
            if (abs($d) < $fpmin) {
                $d = $fpmin;
            }
            $c = 1 + $aa / $c;
            if (abs($c) < $fpmin) {
                $c = $fpmin;
            }
            $d = 1 / $d;
            $h *= $d * $c;
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1 + $aa * $d;
            if (abs($d) < $fpmin) {
                $d = $fpmin;
            }
            $c = 1 + $aa / $c;
            if (abs($c) < $fpmin) {
                $c = $fpmin;
            }
            $d = 1 / $d;
            $del = $d * $c;
            $h *= $del;
            if (abs($del - 1.0) < $eps) {
                break;
            }
        }
        return $h;
    }

    /** Lanczos log-gamma. */
    public static function lgamma(float $x): float
    {
        $coef = [
            0.99999999999980993, 676.5203681218851, -1259.1392167224028,
            771.32342877765313, -176.61502916214059, 12.507343278686905,
            -0.13857109526572012, 9.9843695780195716e-6, 1.5056327351493116e-7,
        ];
        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - self::lgamma(1 - $x);
        }
        $x -= 1;
        $sum = $coef[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += $coef[$i] / ($x + $i);
        }
        $t = $x + 7.5;
        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }

    /** Student-t CDF. */
    public static function studentTCdf(float $t, int $df): float
    {
        if ($df < 1) {
            throw new \InvalidArgumentException('df must be >= 1.');
        }
        $x = $df / ($df + $t * $t);
        $ib = self::regularizedBeta($x, $df / 2, 0.5);
        return $t >= 0 ? 1 - 0.5 * $ib : 0.5 * $ib;
    }

    /**
     * Student-t quantile: Cornish-Fisher initial guess, then bisection on the
     * exact CDF to machine-ish precision. Deterministic.
     */
    public static function studentTQuantile(float $p, int $df): float
    {
        if ($df < 1) {
            throw new \InvalidArgumentException('df must be >= 1.');
        }
        if ($p <= 0.0 || $p >= 1.0) {
            throw new \InvalidArgumentException('p must be in (0,1).');
        }
        $z = self::normalQuantile($p);
        $v = (float) $df;
        $z2 = $z * $z;
        $g1 = ($z2 * $z + $z) / 4.0;
        $g2 = (5 * $z2 * $z2 * $z + 16 * $z2 * $z + 3 * $z) / 96.0;
        $guess = $z + $g1 / $v + $g2 / ($v * $v);

        $lo = $guess - 2.0;
        $hi = $guess + 2.0;
        while (self::studentTCdf($lo, $df) > $p) {
            $lo -= 2.0;
        }
        while (self::studentTCdf($hi, $df) < $p) {
            $hi += 2.0;
        }
        for ($i = 0; $i < 80; $i++) {
            $mid = ($lo + $hi) / 2;
            if (self::studentTCdf($mid, $df) < $p) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return ($lo + $hi) / 2;
    }
}
