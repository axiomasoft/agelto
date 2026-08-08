<?php

declare(strict_types=1);

namespace App\Services\Procurement\History;

use App\Dto\Procurement\History\ProcurementDurations;
use App\Dto\Procurement\History\ProcurementTimeline;
use App\Dto\Procurement\History\TimelineEntry;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\History\ProcurementHistoryReadRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Строит историю по каноническим событиям и ревизиям; архитектурный тест охраняет эту границу. */
final readonly class ProcurementTimelineBuilder
{
    public function __construct(private ProcurementHistoryReadRepository $history) {}

    public function build(Procurement $procurement, Carbon $from, Carbon $until): ProcurementTimeline
    {
        $events = $this->history->eventsForTimeline(procurement: $procurement, from: $from, until: $until);
        $revisions = $this->history->revisionsForTimeline(procurement: $procurement, from: $from, until: $until)->keyBy('id');

        $entries = $events->map(fn (ProcurementEvent $event): TimelineEntry => new TimelineEntry(
            type: ProcurementEventType::from((string) $event->getRawOriginal('event_type')),
            occurredAt: Carbon::parse($event->getRawOriginal('occurred_at')),
            field: $this->nullableString($event->getRawOriginal('field')),
            oldValue: $this->nullableString($event->getRawOriginal('old_value')),
            newValue: $this->nullableString($event->getRawOriginal('new_value')),
            revisionNumber: $event->getRawOriginal('revision_id') === null ? null : (int) $revisions->get((int) $event->getRawOriginal('revision_id'))?->getRawOriginal('revision_number'),
        ))->values()->all();

        return new ProcurementTimeline(entries: $entries, durations: $this->durations(procurement: $procurement, events: $events));
    }

    /** @param Collection<int, ProcurementEvent> $events */
    private function durations(Procurement $procurement, Collection $events): ProcurementDurations
    {
        $discoveredEvent = $events->first(fn (ProcurementEvent $event): bool => $this->eventType($event) === ProcurementEventType::Discovered);
        $discoveredAt = $discoveredEvent === null ? null : $this->occurredAt($discoveredEvent);
        $statusEvents = $events->filter(fn (ProcurementEvent $event): bool => $this->eventType($event) === ProcurementEventType::StatusChanged)->values();
        $byStatus = [];
        $activeStatus = null;
        $activeSince = null;
        $terminalStatus = null;
        $terminalAt = null;

        foreach ($statusEvents as $event) {
            $newStatus = ProcurementStatus::tryFrom($this->nullableString($event->getRawOriginal('new_value')) ?? '');

            if ($newStatus === null) {
                continue;
            }

            if ($activeStatus !== null && $activeSince !== null) {
                $byStatus[$activeStatus->value] = ($byStatus[$activeStatus->value] ?? 0) + (int) $activeSince->diffInSeconds($this->occurredAt($event));
            }

            $activeStatus = $newStatus;
            $activeSince = $this->occurredAt($event);

            if ($newStatus->isTerminal()) {
                $terminalStatus = $newStatus;
                $terminalAt = $this->occurredAt($event);

                break;
            }
        }

        return new ProcurementDurations(
            discoveredAt: $discoveredAt,
            publishedAt: Carbon::parse($procurement->getRawOriginal('published_at')),
            terminalStatus: $terminalStatus,
            terminalAt: $terminalAt,
            totalDuration: $discoveredAt !== null && $terminalAt !== null ? (int) $discoveredAt->diffInSeconds($terminalAt) : null,
            byStatus: $byStatus,
        );
    }

    private function eventType(ProcurementEvent $event): ProcurementEventType
    {
        return ProcurementEventType::from((string) $event->getRawOriginal('event_type'));
    }

    private function occurredAt(ProcurementEvent $event): Carbon
    {
        return Carbon::parse($event->getRawOriginal('occurred_at'));
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
