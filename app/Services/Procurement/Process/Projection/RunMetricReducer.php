<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Projection\MetricDelta;
use App\Dto\Procurement\Process\Projection\ProjectedEvent;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;

/**
 * Счётчик run lifecycle transitions (создание run, closure/cancel discovery). Прогресс и итоговое
 * состояние run здесь НЕ копятся: это current gauge, его читает RunProjector из authoritative
 * rows, поэтому старое или переставленное событие не может вернуть статус назад.
 */
final class RunMetricReducer implements ProjectionReducer
{
    public function key(): string
    {
        return 'run_transitions';
    }

    public function version(): int
    {
        return 1;
    }

    public function entityKinds(): array
    {
        return ['run'];
    }

    public function metrics(): array
    {
        return ['run_transition_total'];
    }

    public function reduce(ProjectedEvent $event): array
    {
        return [new MetricDelta('run_transition_total', MetricCatalog::dimensionKey('run_transition_total', ['state' => $event->newState]))];
    }
}
