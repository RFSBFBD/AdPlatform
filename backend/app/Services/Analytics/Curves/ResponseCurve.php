<?php

namespace App\Services\Analytics\Curves;

/**
 * Closed-form response curves. All are linear in the scale parameter `a` given
 * the shape parameter `b`, so the fitter solves `a` exactly and grids `b`.
 *
 *  - linear:     Y = a * x                    (constant marginal; LP corner solutions)
 *  - log:        Y = a * ln(1 + b x)          (concave)
 *  - power:      Y = a * x^b, 0 < b < 1       (concave; b >= 1 is non-concave and
 *                                              excluded from optimization by the fitter)
 *  - saturation: Y = a * (1 - e^(-b x))       (concave; same family as the
 *                                              previously assumed OR curve)
 */
final class ResponseCurve implements ResponseCurveContract
{
    private function __construct(
        private readonly string $name,
        private readonly float $a,
        private readonly float $b,
        private readonly float $supportMax,
        private readonly float $supportMin = 0.0,
        private readonly ?float $knot = null,
    ) {
        if(in_array($name,['piecewise','quadratic_spline'],true) && ($knot===null || !is_finite($knot) || $knot<=0 || $b<0 || $b>1)){throw new \InvalidArgumentException('Piecewise knot must be positive and slope ratio in[0,1].');}
        if (!is_finite($a) || $a<=0 || !is_finite($b) || !is_finite($supportMin) || !is_finite($supportMax) || $supportMin<0 || $supportMax<$supportMin) { throw new \InvalidArgumentException('Response curve parameters/support must be finite and valid.'); }
        if (in_array($name,['log','saturation'],true) && $b<=0 || $name==='power' && ($b<=0 || $b>=1)) { throw new \InvalidArgumentException('Response shape must be positive, monotone and concave; power exponent is strictly between zero and one.'); }
    }

    /** Concave two-slope hinge. Discrete menu route only: classical KKT derivative at a kink is insufficient. */
    public static function piecewise(float $a,float $ratio,float $knot,float $supportMax,float $supportMin=0):self{return new self('piecewise',$a,$ratio,$supportMax,$supportMin,$knot);}

    /** C1 concave quadratic-to-linear spline, b is terminal/initial slope ratio. */
    public static function quadraticSpline(float $a,float $ratio,float $knot,float $supportMax,float $supportMin=0):self{return new self('quadratic_spline',$a,$ratio,$supportMax,$supportMin,$knot);}

    public static function linear(float $a, float $supportMax, float $supportMin = 0.0): self
    {
        return new self('linear', $a, 0.0, $supportMax, $supportMin);
    }

    public static function log(float $a, float $b, float $supportMax, float $supportMin = 0.0): self
    {
        return new self('log', $a, $b, $supportMax, $supportMin);
    }

    public static function power(float $a, float $b, float $supportMax, float $supportMin = 0.0): self
    {
        return new self('power', $a, $b, $supportMax, $supportMin);
    }

    public static function saturation(float $a, float $b, float $supportMax, float $supportMin = 0.0): self
    {
        return new self('saturation', $a, $b, $supportMax, $supportMin);
    }

    /** Convert a daily curve to a period scenario assuming equal daily spend.
     * F_period(B) = days * f_daily(B / days). This is not a causal forecast.
     */
    public function overDays(int $days): self
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('days must be positive');
        }
        $a = match ($this->name) {
            'linear','piecewise','quadratic_spline' => $this->a,
            'power' => $this->a * pow($days, 1.0 - $this->b),
            default => $this->a * $days,
        };
        $b = in_array($this->name, ['log', 'saturation'], true) ? $this->b / $days : $this->b;
        return new self($this->name, $a, $b, $this->supportMax * $days, $this->supportMin * $days, $this->knot===null?null:$this->knot*$days);
    }

    public function revenueAt(float $x): float
    {
        if ($x <= 0) {
            return 0.0;
        }
        return match ($this->name) {
            'linear' => $this->a * $x,
            'quadratic_spline' => $this->a*($x<=$this->knot?$x-(1-$this->b)*$x*$x/(2*$this->knot):$this->knot*(1+$this->b)/2+$this->b*($x-$this->knot)),
            'piecewise' => $this->a*(min($x,$this->knot)+$this->b*max(0,$x-$this->knot)),
            'log' => $this->a * log1p($this->b * $x),
            'power' => $this->a * pow($x, $this->b),
            'saturation' => $this->a * (-expm1(-$this->b * $x)),
        };
    }

    public function marginalAt(float $x): float
    {
        $x = max(0.0, $x);
        return match ($this->name) {
            'linear' => $this->a,
            'quadratic_spline' => $this->a*(1-(1-$this->b)*min($x/$this->knot,1)),
            'piecewise' => $x<$this->knot?$this->a:$this->a*$this->b,
            'log' => $this->a * $this->b / (1.0 + $this->b * $x),
            'power' => $this->a * $this->b * pow(max($x, 1e-9), $this->b - 1.0),
            'saturation' => $this->a * $this->b * exp(-$this->b * $x),
        };
    }

    public function spendForMarginal(float $lambda, float $lo, float $hi): float
    {
        if(in_array($this->name,['piecewise','quadratic_spline'],true))throw new \LogicException('Kinked curves require the discrete menu solver, not smooth water-filling.');
        if ($lambda <= 0) {
            return $hi;
        }
        $maxMarginal = $this->marginalAt(0.0);
        if ($this->name !== 'linear' && $lambda >= $maxMarginal) {
            return $lo;
        }
        $x = match ($this->name) {
            // Constant marginal: above it spend nothing, below it take the bound.
            // The optimizer's residual-split handles the tie at lambda == a.
            'linear' => $lambda < $this->a ? $hi : $lo,
            'log' => ($this->a * $this->b / $lambda - 1.0) / $this->b,
            'power' => pow($lambda / ($this->a * $this->b), 1.0 / ($this->b - 1.0)),
            'saturation' => log($this->a * $this->b / $lambda) / $this->b,
        };
        if (!is_finite($x)) {
            return $lambda < $this->marginalAt(0.0) ? $hi : $lo;
        }
        return max($lo, min($hi, $x));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function parameters(): array
    {
        return ['a' => $this->a, 'b' => $this->b]+(in_array($this->name,['piecewise','quadratic_spline'],true)?['knot'=>$this->knot]:[]);
    }

    public function isStrictlyConcave(): bool
    {
        return !in_array($this->name,['linear','piecewise','quadratic_spline'],true);
    }

    public function supportMinSpend(): float { return $this->supportMin; }

    public function supportMaxSpend(): float
    {
        return $this->supportMax;
    }
}
