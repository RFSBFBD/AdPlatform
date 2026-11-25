<?php
namespace App\Services\Analytics\Curves;
/** Positive output scaling preserves concavity and bounds. No probability interpretation. */
final class ScaledResponseCurve implements ResponseCurveContract
{
    public function __construct(private ResponseCurveContract $base,private float $scale)
    {
        if (!is_finite($scale) || $scale<=0) { throw new \InvalidArgumentException('Response scale must be positive and finite.'); }
    }
    public function revenueAt(float $spend): float { return $this->scale*$this->base->revenueAt($spend); }
    public function marginalAt(float $spend): float { return $this->scale*$this->base->marginalAt($spend); }
    public function spendForMarginal(float $lambda,float $lo,float $hi): float { return $this->base->spendForMarginal($lambda/$this->scale,$lo,$hi); }
    public function name(): string { return 'scaled:'.$this->base->name(); }
    public function parameters(): array { return ['scale'=>$this->scale,'base_name'=>$this->base->name(),'base_params'=>$this->base->parameters()]; }
    public function isStrictlyConcave(): bool { return $this->base->isStrictlyConcave(); }
    public function supportMinSpend(): float { return method_exists($this->base,'supportMinSpend')?$this->base->supportMinSpend():0.0; }
    public function supportMaxSpend(): float { return $this->base->supportMaxSpend(); }
}
