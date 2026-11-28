<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

use App\Enums\Procurement\Process\Projection\MeasurementState;

/** A measured gauge/ratio, never a persisted counter or an inferred health verdict. */
final readonly class MetricMeasurement
{
    public function __construct(
        public MeasurementState $state,
        public int|float|null $value,
        public ?string $reason = null,
    ) {
        if (($state === MeasurementState::Known) !== ($value !== null)
            || ($value !== null && (! is_finite((float) $value) || $value < 0))
            || ($state !== MeasurementState::Known && $reason === null)) {
            throw new \InvalidArgumentException('Measurement needs a value or an explicit unavailable reason.');
        }
    }

    public function label(): string
    {
        return $this->value === null ? $this->state->value.' ('.$this->reason.')' : (string) $this->value;
    }
}
