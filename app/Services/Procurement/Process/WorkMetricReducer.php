<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\MetricDelta;
use App\Dto\Procurement\Process\ProjectedEvent;
use App\Enums\Procurement\Process\WorkState;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;

/**
 * Счётчики accepted work transitions по времени самого перехода: stage/state считаются в момент
 * события и не пересчитываются от текущего состояния work (prepare t0 остаётся в t0, даже если
 * extraction позже изменил документ). stage_deferred_total — ровно по deferred transition, а не
 * по capacity polling или повторной доставке: дубликат события отсекает receipt.
 */
final class WorkMetricReducer implements ProjectionReducer
{
    public function key(): string
    {
        return 'work_transitions';
    }

    public function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public function entityKinds(): array
    {
        return ['work'];
    }

    /** @return list<string> */
    public function metrics(): array
    {
        return ['work_transition_total', 'stage_deferred_total'];
    }

    /** @return list<MetricDelta> */
    public function reduce(ProjectedEvent $event): array
    {
        $source = $event->workSource();
        if ($source === null) {
            // Source work удалён либо событие не work: нельзя восстановить измерения, не придумывая их.
            throw new ProjectionSchemaError('work_source_missing');
        }
        $platform = MetricCatalog::platformOf($source['cohort']);
        $deltas = [new MetricDelta('work_transition_total', MetricCatalog::dimensionKey('work_transition_total', [
            'platform' => $platform, 'stage' => $source['stage'], 'state' => $event->newState,
        ]))];
        if ($event->newState === WorkState::Deferred->value) {
            $deltas[] = new MetricDelta('stage_deferred_total', MetricCatalog::dimensionKey('stage_deferred_total', [
                'platform' => $platform, 'stage' => $source['stage'], 'reason' => $event->reasonCode ?? '',
            ]));
        }

        return $deltas;
    }
}
