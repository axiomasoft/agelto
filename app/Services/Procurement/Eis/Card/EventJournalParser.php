<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis\Card;

use App\Dto\Procurement\Details\JournalEntryData;
use App\Enums\Procurement\ProcurementEventType;
use App\Services\Procurement\Eis\EisSourceClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `event-journal` карточки закупки ЕИС (`research/eis-card-recon.md`,
 * `docs/dev/crawling-pipeline.md` §5.1) в список {@see JournalEntryData}. Только
 * HTML → DTO (дата + сырой текст строки), без маппинга в {@see ProcurementEventType}
 * (это `EventJournalProcessor`) — вкладка хрупкая (`docs/dev/database.md` §3), структура не
 * подтверждена публичной схемой. Строка с нераспознаваемой датой — пропускается, без
 * даты нет партиционного ключа `prc_events.occurred_at`.
 */
final readonly class EventJournalParser
{
    public function __construct(private EisSourceClock $clock = new EisSourceClock) {}

    private const string DATE_FORMAT = '!d.m.Y H:i';

    /**
     * @return list<JournalEntryData>
     */
    public function parse(string $html): array
    {
        try {
            $crawler = new Crawler($html);
            $table = $crawler->filter('table#event');

            if ($table->count() === 0) {
                $this->warnIfJournalEvidenceSuppressed($crawler, recognized: 0);

                return [];
            }

            $entries = [];

            $table->filter('tbody tr')->each(function (Crawler $tr) use (&$entries): void {
                $entry = $this->parseRow($tr);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            });

            $this->warnIfJournalEvidenceSuppressed($crawler, recognized: count($entries));

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

    /**
     * Тихий отказ (`research/10-p4-remediation.md` §3.7): страница несёт признаки таблицы
     * событий (`.timeZoneName`), а распознано 0 записей — признак разошедшейся вёрстки,
     * обязан быть виден в логе.
     */
    private function warnIfJournalEvidenceSuppressed(Crawler $crawler, int $recognized): void
    {
        if ($recognized > 0) {
            return;
        }

        $timeZoneMarkers = $crawler->filter('span.timeZoneName')->count();

        if ($timeZoneMarkers > 0) {
            Log::warning('На вкладке event-journal есть признаки таблицы событий, но не распознано ни одной записи', [
                'timezone_markers' => $timeZoneMarkers,
            ]);
        }
    }

    private function parseDate(string $value): ?Carbon
    {
        // ЕИС отдаёт «30.06.2026 08:22 (МСК+3)»: явная метка «МСК±N» задаёт смещение
        // (МСК = UTC+3); без метки время читается в часовом поясе источника. Результат — UTC.
        $timezone = null;

        if (preg_match('/\(([^()]*)\)\s*$/u', $value, $matches) === 1) {
            $timezone = $this->clock->timezoneFromMskLabel(label: $matches[1]);
        }

        $value = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $value));

        if ($value === '') {
            return null;
        }

        return $this->clock->parseDateTime(format: self::DATE_FORMAT, value: $value, timezone: $timezone);
    }
}
