<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Приращение одной counter-серии; dimension_key строит и валидирует только MetricCatalog. */
final readonly class MetricDelta
{
    public function __construct(public string $metricKey, public string $dimensionKey, public int $amount = 1)
    {
        ProcessValue::text($metricKey);
        ProcessValue::text($dimensionKey);
        ProcessValue::positive($amount);
    }
}
