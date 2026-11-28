<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Parsing\Primitives\EventClassificationRule;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Telemetry\LogChannel;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\PartitionCoverage;
use App\Services\Procurement\Parsing\Validation\SafeRegex;

/**
 * Дополняет prc_events датами переходов этапов из event-journal. Протоколы, awards и bids
 * обрабатываются отдельно. Неизвестные текстовые шаблоны пропускаются: публичной схемы
 * журнала нет, поэтому разбор сохраняет только распознанные факты.
 */
final readonly class EventJournalProcessor
{
    public function __construct(
        private ProcurementStoreRepository $storeRepository,
        private PartitionCoverage $partitions,
    ) {}

    /**
     * @param  list<JournalEntryData>  $entries
     */
    public function process(Procurement $procurement, array $entries): void
    {
        $this->ensurePartitions($entries);
        $this->storePrepared(procurement: $procurement, entries: $entries);
    }

    /**
     * @param  list<JournalEntryData>  $entries
     * @param  list<EventClassificationRule>|null  $patterns
     */
    public function ensurePartitions(array $entries, ?array $patterns = null): void
    {
        $dates = [];
        foreach ($entries as $entry) {
            if ($this->mapEntry($entry, $patterns) !== null) {
                $dates[] = $entry->occurredAt;
            }
        }
        $this->partitions->ensureMonths(table: 'prc_events', dates: $dates);
    }

    /**
     * @param  list<JournalEntryData>  $entries
     * @param  list<EventClassificationRule>|null  $patterns
     */
    public function storePrepared(Procurement $procurement, array $entries, ?array $patterns = null): void
    {
        $skipped = 0;

        foreach ($entries as $entry) {
            $mapped = $this->mapEntry($entry, $patterns);

            if ($mapped === null) {
                $skipped++;

                LogChannel::Procurement->warning('Строка журнала событий не распознана и пропущена', [
                    'procurement_id' => $procurement->id,
                    'text' => $entry->rawText,
                ]);

                continue;
            }

            [$eventType, $newValue] = $mapped;
            $occurredAt = $entry->occurredAt->copy()->utc();

            if ($this->storeRepository->hasEvent(
                procurementId: $procurement->id,
                eventType: $eventType,
                occurredAt: $occurredAt,
                newValue: $newValue,
            )) {
                continue;
            }

            $this->storeRepository->storeEvent(
                procurementId: $procurement->id,
                eventType: $eventType,
                occurredAt: $occurredAt,
                newValue: $newValue,
                payload: ['source' => 'event_journal', 'raw_text' => $entry->rawText],
            );
        }

        if ($skipped > 0) {
            LogChannel::Procurement->warning('Журнал событий: часть строк не распознана', [
                'procurement_id' => $procurement->id,
                'skipped' => $skipped,
                'total' => count($entries),
            ]);
        }
    }

    /**
     * @param  list<EventClassificationRule>|null  $patterns
     * @return array{0: ProcurementEventType, 1: ?string}|null
     */
    private function mapEntry(JournalEntryData $entry, ?array $patterns = null): ?array
    {
        if ($patterns !== null && $patterns !== []) {
            usort(
                $patterns,
                static fn (EventClassificationRule $a, EventClassificationRule $b): int => $b->priority <=> $a->priority,
            );

            foreach ($patterns as $rule) {
                if ($rule->pattern !== null) {
                    $matches = [];
                    if (SafeRegex::match($rule->pattern, $entry->rawText, $matches)) {
                        $newValue = $rule->eventType === ProcurementEventType::StatusChanged && isset($matches[1]) && is_string($matches[1])
                            ? trim($matches[1])
                            : null;

                        return [$rule->eventType, $newValue];
                    }
                }

                foreach ($rule->keywords as $keyword) {
                    if (mb_stripos($entry->rawText, $keyword) !== false) {
                        return [$rule->eventType, null];
                    }
                }
            }

            return null;
        }

        if (preg_match('/переведена?\s+на\s+этап[аеу]?\s*[«"]([^»"]+)[»"]/ui', $entry->rawText, $matches) === 1) {
            return [ProcurementEventType::StatusChanged, trim($matches[1])];
        }

        if (preg_match('/отменен/ui', $entry->rawText) === 1) {
            return [ProcurementEventType::Cancelled, null];
        }

        if (preg_match('/восстановлен/ui', $entry->rawText) === 1) {
            return [ProcurementEventType::Restored, null];
        }

        return null;
    }
}
