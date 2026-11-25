<?php

namespace App\Services\Analytics\Curves;

/**
 * A monotone response curve Y(x): expected response (e.g. reported revenue)
 * for a spend level x. Implementations must be deterministic.
 *
 * Fitted curves are descriptive fits of observational history: they do NOT
 * prove causal budget lift, and evaluation above `supportMaxSpend()` is
 * extrapolation that callers must flag (Master Spec sections 17-19).
 */
interface ResponseCurveContract
{
    public function revenueAt(float $spend): float;

    /** dY/dx at x, with right derivative convention for an explicitly discrete-only kinked family. */
    public function marginalAt(float $spend): float;

    /** Smooth-route inverse only. Discrete-only flexible families explicitly throw instead of entering KKT. */
    public function spendForMarginal(float $lambda, float $lo, float $hi): float;

    /** Model name: linear | log | power | saturation | piecewise | quadratic_spline. */
    public function name(): string;

    /** Fitted or assumed parameters, for reproducibility records. */
    public function parameters(): array;

    /** Strictly concave curves give a unique interior KKT optimum. */
    public function isStrictlyConcave(): bool;

    /** Largest spend observed in the data the curve was built from (support bound). */
    public function supportMaxSpend(): float;
}
