<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Parsing\Extraction\DriftSignal;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\ProcurementEventType;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Platform\Contracts\DriftCollector;
use App\Services\Procurement\Platform\Contracts\EventTableExtractor;
use Illuminate\Support\Carbon;
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
    public function __construct(
        private EisSourceClock $clock = new EisSourceClock,
        private ?DetailRuleSetDto $rules = null,
        private ?EventTableExtractor $extractor = null,
        private ?DriftCollector $drift = null,
    ) {}

    /**
     * @return list<JournalEntryData>
     */
    public function parse(string $html, bool $strict = false): array
    {
        try {
            $rules = $this->rules ?? EisDefaultParsingRules::detail();
            $crawler = new Crawler($html);
            $journalRule = $rules->eventJournal;
            $entries = [];

            if ($this->extractor !== null && $journalRule !== null && $journalRule->rowSelectors !== null && $journalRule->descriptionSelectors !== null) {
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
                $extracted = $this->extractor->extract(new \DOMXPath($dom), $journalRule);
                foreach ($extracted as $item) {
                    $occurredAt = $item['date'] !== null ? $this->parseDate($item['date']) : null;
                    $text = trim($item['description']);
                    if ($occurredAt !== null && $text !== '') {
                        $entries[] = new JournalEntryData(occurredAt: $occurredAt, rawText: $text);
                    }
                }
            } else {
                $rows = $this->firstMatching($crawler, $journalRule?->rowSelectors, 'table#event tbody tr');
                if ($rows->count() > 0) {
                    $rows->each(function (Crawler $tr) use (&$entries, $journalRule): void {
                        $entry = $this->parseRow($tr, $journalRule?->dateSelectors, $journalRule?->descriptionSelectors);

                        if ($entry !== null) {
                            $entries[] = $entry;
                        }
                    });
                }
            }

            if ($entries === [] && $crawler->filter('table#event')->count() === 0) {
                $this->drift?->record(new DriftSignal('event_journal_table_missing', StageCode::Detail, 'table#event'));
            }

            $this->warnIfJournalEvidenceSuppressed($crawler, recognized: count($entries));

            return $entries;
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            CardDomHelper::logWarning('Разбор вкладки event-journal провалился целиком, журнал пропущен', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function firstMatching(Crawler $crawler, ?SelectorChain $chain, string $defaultSelector): Crawler
    {
        if ($chain !== null) {
            foreach ($chain->selectors as $selector) {
                $matched = $crawler->filter($selector->selector);
                if ($matched->count() > 0) {
                    return $matched;
                }
            }

            return new Crawler;
        }

        return $crawler->filter($defaultSelector);
    }

    private function parseRow(Crawler $tr, ?SelectorChain $dateSelectors = null, ?SelectorChain $descriptionSelectors = null): ?JournalEntryData
    {
        $dateNode = $dateSelectors !== null ? $this->firstMatching($tr, $dateSelectors, 'td:nth-child(1)') : $tr->filter('td')->eq(0);
        $textNode = $descriptionSelectors !== null ? $this->firstMatching($tr, $descriptionSelectors, 'td:nth-child(2)') : $tr->filter('td')->eq(1);

        if ($dateNode->count() === 0 || $textNode->count() === 0) {
            return null;
        }

        $rawDate = trim($dateNode->text());
        $occurredAt = $this->parseDate($rawDate);

        if ($occurredAt === null) {
            CardDomHelper::logWarning('Строка вкладки event-journal с нераспознаваемой датой пропущена', [
                'date' => $rawDate,
            ]);

            return null;
        }

        $text = trim($textNode->text());

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
            CardDomHelper::logWarning('На вкладке event-journal есть признаки таблицы событий, но не распознано ни одной записи', [
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
            if ($timezone === null) {
                CardDomHelper::logWarning('Неизвестная метка часового пояса журнала ЕИС, использован пояс источника', [
                    'timezone' => $matches[1],
                ]);
            }
        }

        $value = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $value));

        if ($value === '') {
            return null;
        }

        return $this->clock->parseDateTime(format: '!d.m.Y H:i:s', value: $value, timezone: $timezone)
            ?? $this->clock->parseDateTime(format: '!d.m.Y H:i', value: $value, timezone: $timezone);
    }
}
