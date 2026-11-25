<?php

namespace App\Services\Analytics\DTO;

/**
 * One number with its provenance contract.
 *
 * Every metric the platform shows carries a type (Master Spec section 12):
 *  - ACTUAL:   computed from observed platform-reported data only.
 *  - EXPECTED: output of a statistical / forecasting model.
 *  - SCENARIO: output of a simulation where at least one input was changed on purpose.
 *
 * A value that cannot be computed is null with a machine-readable reason; nothing is
 * fabricated to fill it. Intervals appear only when statistically grounded; otherwise
 * lower/upper are null and `reason` says why.
 */
final class MetricValue
{
    public const ACTUAL = 'ACTUAL';
    public const EXPECTED = 'EXPECTED';
    public const SCENARIO = 'SCENARIO';

    public function __construct(
        public readonly string $type,
        public readonly ?float $value,
        public readonly ?float $lower = null,
        public readonly ?float $upper = null,
        public readonly ?float $confidenceLevel = null,
        public readonly ?int $sampleSize = null,
        public readonly ?string $unit = null,
        public readonly ?string $reason = null,
        public readonly array $lineage = [],
    ) {}

    public static function actual(?float $value, ?string $unit = null, array $lineage = [], ?string $reason = null, ?int $sampleSize = null): self
    {
        return new self(self::ACTUAL, $value, null, null, null, $sampleSize, $unit, $reason, $lineage);
    }

    public static function unavailable(string $type, string $reason, array $lineage = []): self
    {
        return new self($type, null, null, null, null, null, null, $reason, $lineage);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'lower' => $this->lower,
            'upper' => $this->upper,
            'confidence_level' => $this->confidenceLevel,
            'sample_size' => $this->sampleSize,
            'unit' => $this->unit,
            'reason' => $this->reason,
            'lineage' => $this->lineage,
        ];
    }
}
