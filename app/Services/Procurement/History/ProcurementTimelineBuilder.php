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
use App\Models\Procurement\ProcurementRevision;
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
            type: $this->eventType($event),
            occurredAt: $this->occurredAt($event),
            field: $this->nullableString($event->getRawOriginal('field')),
            oldValue: $this->nullableString($event->getRawOriginal('old_value')),
            newValue: $this->nullableString($event->getRawOriginal('new_value')),
            revisionNumber: $this->revisionNumber(event: $event, revisions: $revisions),
        ))->values()->all();

        /** @var list<TimelineEntry> $entries */
        $entries = array_values($entries);

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

        if ($statusEvents->isEmpty() && $discoveredAt !== null) {
            $legacyStatus = $this->legacyInitialStatus(procurement: $procurement);

            if ($legacyStatus !== null) {
                $activeStatus = $legacyStatus;
                $activeSince = $discoveredAt;

                if ($legacyStatus->isTerminal()) {
                    $terminalStatus = $legacyStatus;
                    $terminalAt = $discoveredAt;
                    $byStatus[$legacyStatus->value] = 0;
                }
            }
        }

        foreach ($statusEvents as $event) {
            $newStatus = ProcurementStatus::tryFrom($this->nullableString($event->getRawOriginal('new_value')) ?? '');

            if ($newStatus === null) {
                continue;
            }

            $isInitialStatus = $activeStatus === null;

            if ($activeStatus !== null && $activeSince !== null) {
                $byStatus[$activeStatus->value] = ($byStatus[$activeStatus->value] ?? 0) + (int) $activeSince->diffInSeconds($this->occurredAt($event));
            }

            $activeStatus = $newStatus;
            $activeSince = $this->occurredAt($event);

            if ($newStatus->isTerminal()) {
                $terminalStatus = $newStatus;
                $terminalAt = $this->occurredAt($event);

                if ($isInitialStatus) {
                    $byStatus[$newStatus->value] = 0;
                }

                break;
            }
        }

        return new ProcurementDurations(
            discoveredAt: $discoveredAt,
            publishedAt: $this->rawDate($procurement->getRawOriginal('published_at'), 'published_at'),
            terminalStatus: $terminalStatus,
            terminalAt: $terminalAt,
            totalDuration: $discoveredAt !== null && $terminalAt !== null ? (int) $discoveredAt->diffInSeconds($terminalAt) : null,
            byStatus: $byStatus,
        );
    }

    /**
     * Legacy discovered-only histories contain no temporal evidence of a later transition.
     * A one-revision procurement therefore safely treats its persisted status as initial; a
     * multi-revision history remains unknown rather than inventing an intermediate status.
     */
    private function legacyInitialStatus(Procurement $procurement): ?ProcurementStatus
    {
        if ($this->rawInt($procurement->getRawOriginal('revision_count'), 'revision_count') !== 1) {
            return null;
        }

        return ProcurementStatus::tryFrom($this->rawString($procurement->getRawOriginal('status'), 'status'));
    }

    private function eventType(ProcurementEvent $event): ProcurementEventType
    {
        return ProcurementEventType::from($this->rawString($event->getRawOriginal('event_type'), 'event_type'));
    }

    private function occurredAt(ProcurementEvent $event): Carbon
    {
        return $this->rawDate($event->getRawOriginal('occurred_at'), 'occurred_at');
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->rawString($value, 'timeline value');
    }

    /** @param Collection<int, ProcurementRevision> $revisions */
    private function revisionNumber(ProcurementEvent $event, Collection $revisions): ?int
    {
        $rawRevisionId = $event->getRawOriginal('revision_id');

        if ($rawRevisionId === null) {
            return null;
        }

        $revision = $revisions->get($this->rawInt($rawRevisionId, 'revision_id'));

        if (! $revision instanceof ProcurementRevision) {
            return null;
        }

        return $this->rawInt($revision->getRawOriginal('revision_number'), 'revision_number');
    }

    private function rawDate(mixed $value, string $field): Carbon
    {
        return Carbon::parse($this->rawString($value, $field));
    }

    private function rawString(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new \RuntimeException("Raw field {$field} должен быть строкой.");
        }

        return $value;
    }

    private function rawInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \RuntimeException("Raw field {$field} должен быть целым числом.");
    }
}
