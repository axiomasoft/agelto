<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Crawler\Details\JournalEntryData;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Facades\Log;

/**
 * DTO → `prc_events` для вкладки `event-journal` — **дополняющий**, не первичный источник
 * (`01-database.md` §3): здесь только факты-даты переходов этапов из текстовых строк
 * журнала, никаких протоколов/awards/bids (это P3.7, D18). Строка журнала, не
 * подходящая ни под один известный паттерн, — пропускается (best-effort, `02` §9):
 * структура вкладки не подтверждена публичной схемой.
 */
final readonly class EventJournalProcessor
{
    public function __construct(
        private ProcurementStoreRepository $storeRepository,
    ) {}

    /**
     * @param  list<JournalEntryData>  $entries
     */
    public function process(Procurement $procurement, array $entries): void
    {
        $skipped = 0;

        foreach ($entries as $entry) {
            $mapped = $this->mapEntry($entry);

            if ($mapped === null) {
                $skipped++;

                Log::warning('Строка журнала событий не распознана и пропущена', [
                    'procurement_id' => $procurement->id,
                    'text' => $entry->rawText,
                ]);

                continue;
            }

            [$eventType, $newValue] = $mapped;

            if ($this->storeRepository->hasEvent(
                procurementId: $procurement->id,
                eventType: $eventType,
                occurredAt: $entry->occurredAt,
                newValue: $newValue,
            )) {
                continue;
            }

            $this->storeRepository->storeEvent(
                procurementId: $procurement->id,
                eventType: $eventType,
                occurredAt: $entry->occurredAt,
                newValue: $newValue,
                payload: ['source' => 'event_journal', 'raw_text' => $entry->rawText],
            );
        }

        if ($skipped > 0) {
            Log::warning('Журнал событий: часть строк не распознана', [
                'procurement_id' => $procurement->id,
                'skipped' => $skipped,
                'total' => count($entries),
            ]);
        }
    }

    /**
     * @return array{0: ProcurementEventType, 1: ?string}|null
     */
    private function mapEntry(JournalEntryData $entry): ?array
    {
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
