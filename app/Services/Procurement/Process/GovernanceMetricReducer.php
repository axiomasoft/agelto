<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\MetricDelta;
use App\Dto\Procurement\Process\ProjectedEvent;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;

/** Счётчики operator control mutations и incident lifecycle; incident kind/scope — не dimension. */
final class GovernanceMetricReducer implements ProjectionReducer
{
    public function key(): string
    {
        return 'governance_transitions';
    }

    public function version(): int
    {
        return 1;
    }

    public function entityKinds(): array
    {
        return ['control', 'incident'];
    }

    public function metrics(): array
    {
        return ['control_transition_total', 'incident_transition_total'];
    }

    public function reduce(ProjectedEvent $event): array
    {
        return match ($event->entityKind) {
            'control' => [new MetricDelta('control_transition_total', MetricCatalog::dimensionKey('control_transition_total', ['mode' => $event->newState]))],
            'incident' => [new MetricDelta('incident_transition_total', MetricCatalog::dimensionKey('incident_transition_total', ['state' => $event->newState]))],
            default => throw new ProjectionSchemaError('entity_kind_unsupported'),
        };
    }
}
