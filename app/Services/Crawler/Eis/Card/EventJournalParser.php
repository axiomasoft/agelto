<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis\Card;

use App\Dto\Crawler\Details\JournalEntryData;
use App\Enums\Procurement\ProcurementEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `event-journal` карточки закупки ЕИС (`research/eis-card-recon.md`,
 * `docs/specs/v2/02-crawling.md` §5.1) в список {@see JournalEntryData}. Только
 * HTML → DTO (дата + сырой текст строки), без маппинга в {@see ProcurementEventType}
 * (это `EventJournalProcessor`) — вкладка хрупкая (`01-database.md` §3), структура не
 * подтверждена публичной схемой. Строка с нераспознаваемой датой — пропускается, без
 * даты нет партиционного ключа `prc_events.occurred_at`.
 */
final readonly class EventJournalParser
{
    private const string DATE_FORMAT = 'd.m.Y H:i';

    /**
     * @return list<JournalEntryData>
     */
    public function parse(string $html): array
    {
        try {
            $table = (new Crawler($html))->filter('div.event-journal table.event-journal-table');

            if ($table->count() === 0) {
                return [];
            }

            $entries = [];

            $table->filter('tbody tr')->each(function (Crawler $tr) use (&$entries): void {
                $entry = $this->parseRow($tr);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            });

            return $entries;
        } catch (\Throwable $exception) {
            Log::warning('Разбор вкладки event-journal провалился целиком, журнал пропущен', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function parseRow(Crawler $tr): ?JournalEntryData
    {
        $cells = $tr->filter('td');

        if ($cells->count() < 2) {
            return null;
        }

        $occurredAt = $this->parseDate(trim($cells->eq(0)->text()));

        if ($occurredAt === null) {
            Log::warning('Строка вкладки event-journal с нераспознаваемой датой пропущена', [
                'date' => trim($cells->eq(0)->text()),
            ]);

            return null;
        }

        $text = trim($cells->eq(1)->text());

        if ($text === '') {
            return null;
        }

        return new JournalEntryData(occurredAt: $occurredAt, rawText: $text);
    }

    private function parseDate(string $value): ?Carbon
    {
        // ЕИС отдаёт «30.06.2026 08:22 (МСК+3)» — часовой пояс в источнике не парсим,
        // отбрасываем скобочный суффикс и читаем как naive datetime.
        $value = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $value));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(self::DATE_FORMAT, $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
