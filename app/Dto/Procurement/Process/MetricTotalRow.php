<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Значение counter-серии активной generation, прочитанное одним снимком с её state. */
final readonly class MetricTotalRow
{
    public function __construct(public string $metricKey, public string $dimensionKey, public int $value)
    {
        ProcessValue::text($metricKey);
        ProcessValue::text($dimensionKey);
        ProcessValue::nonNegative($value);
    }
}
