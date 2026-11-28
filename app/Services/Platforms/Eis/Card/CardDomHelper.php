<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Parsing\Rules\CardDomRule;
use App\Enums\Telemetry\LogChannel;
use Symfony\Component\DomCrawler\Crawler;

final readonly class CardDomHelper
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function logWarning(string $message, array $context = []): void
    {
        LogChannel::Platforms->warning($message, $context);
    }

    public static function titleSection(Crawler $crawler, string $term, ?CardDomRule $rule = null): ?Crawler
    {
        $labelSelectors = $rule?->labelSelectors !== null ? $rule->labelSelectors->selectors : ['span.section__title'];
        try {
            foreach ($labelSelectors as $selector) {
                $selectorStr = is_string($selector) ? $selector : $selector->selector;
                $title = $crawler
                    ->filter($selectorStr)
                    ->reduce(static fn (Crawler $span): bool => trim($span->text()) === $term);

                if ($title->count() > 0) {
                    $section = $title->ancestors()->first();

                    return $section->count() > 0 ? $section : null;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function infoNode(Crawler $section, ?CardDomRule $rule = null): ?Crawler
    {
        $valueSelectors = $rule?->valueSelectors !== null ? $rule->valueSelectors->selectors : ['span.section__info'];
        foreach ($valueSelectors as $selector) {
            $selectorStr = is_string($selector) ? $selector : $selector->selector;
            $info = $section->filter($selectorStr)->first();
            if ($info->count() > 0) {
                return $info;
            }
        }

        return null;
    }

    public static function readDefinitionValue(Crawler $crawler, string $term, ?CardDomRule $rule = null): ?string
    {
        $section = self::titleSection($crawler, $term, $rule);
        $info = $section !== null ? self::infoNode($section, $rule) : null;

        if ($info === null) {
            return null;
        }

        $value = trim($info->text());

        return $value !== '' ? $value : null;
    }

    public static function readDefinitionLink(Crawler $crawler, string $term, ?CardDomRule $rule = null): ?string
    {
        $section = self::titleSection($crawler, $term, $rule);
        $info = $section !== null ? self::infoNode($section, $rule) : null;
        $link = $info?->filter('a');

        return $link !== null && $link->count() > 0 ? $link->attr('href') : null;
    }

    public static function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $value) ?? $value);
    }

    public static function normalizeAmount(string $rawAmount): ?string
    {
        if ($rawAmount === '') {
            return null;
        }

        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', $rawAmount);
        $normalized = str_replace(',', '.', (string) $normalized);

        return is_numeric($normalized) ? $normalized : null;
    }

    public static function normalizeMoney(string $text): ?string
    {
        $amount = self::normalizeAmount(preg_replace('/\s*(?:₽|руб\.?|RUB)\s*$/ui', '', $text) ?? $text);

        return $amount !== null && preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $amount) === 1 ? $amount : null;
    }

    public static function extractInn(string $text): ?string
    {
        if (preg_match('/\bИНН\s*:?\s*(\d{12}|\d{10})(?!\d)/ui', $text, $match) === 1) {
            return $match[1];
        }

        return preg_match('/(?<!\d)(\d{12}|\d{10})(?!\d)/u', $text, $match) === 1 ? $match[1] : null;
    }

    public static function warnIfBlockInfoSuppressed(Crawler $crawler, string $message): void
    {
        $sections = $crawler->filter('section.blockInfo__section')->count();

        if ($sections > 0) {
            self::logWarning($message, [
                'sections' => $sections,
            ]);
        }
    }

    public static function parseFiscalYear(string $header): ?int
    {
        return preg_match('/(\d{4})\s*год/u', $header, $matches) === 1 ? (int) $matches[1] : null;
    }

    public static function itemTables(Crawler $crawler): Crawler
    {
        return $crawler->filter('div#positionKTRU table.tableBlock')
            ->reduce(static fn (Crawler $table): bool => $table->ancestors()
                ->reduce(static fn (Crawler $ancestor): bool => $ancestor->nodeName() === 'table')
                ->count() === 0);
    }

    public static function itemRows(Crawler $table): Crawler
    {
        if ($table->count() === 0) {
            return $table;
        }

        return $table->children('tbody')->children('tr')->reduce(static function (Crawler $row): bool {
            if (preg_match('/(?:^|\s)truInfo_\d*(?:\s|$)/', (string) $row->attr('class')) === 1) {
                return false;
            }

            if ($row->children()->count() === 0) {
                return false;
            }

            $cells = $row->children('td');

            return ! ($cells->count() === 1
                && trim($cells->text()) === ''
                && preg_match('/(?:^|\s)pt-0(?:\s|$)/', (string) $cells->attr('class')) === 1);
        });
    }

    public static function expectedItemCount(Crawler $tables): ?int
    {
        if ($tables->count() === 0) {
            return null;
        }

        $expected = 0;
        foreach ($tables as $node) {
            $table = new Crawler($node);
            $total = $table->children('tfoot')->filter('.tableBlock__resultTitle');
            if ($total->count() !== 1 || preg_match('/^(?:Итого|Начальная\s+сумма\s+цен\s+единиц\s+товара,\s*работы,\s*услуги)\s*\((\d+)\s+запис(?:ь|и|ей)\)\s*:/u',
                self::normalizeWhitespace($total->text()), $match) !== 1) {
                return null;
            }

            $tableCount = (int) $match[1];
            if (self::itemRows($table)->count() !== $tableCount) {
                return null;
            }
            $expected += $tableCount;
        }

        return $expected;
    }

    public static function hasCompleteCommonInfoContainer(
        Crawler $crawler,
        string $financingTerm,
        int $parsedFinancingRows,
        int $parsedItems,
    ): bool {
        $itemTable = self::itemTables($crawler);
        $itemRows = self::itemRows($itemTable);
        $expectedItems = self::expectedItemCount($itemTable);
        $financingTable = self::titleSection($crawler, $financingTerm)?->filter('table.tableBlock')->first();
        $financingHeaders = $financingTable?->filter('tr.tableBlock__row th')->each(static fn (Crawler $th): string => trim($th->text())) ?? [];
        $expectedFinancingRows = count(array_filter(
            $financingHeaders,
            static fn (string $header): bool => self::parseFiscalYear($header) !== null,
        ));
        $financingDataRows = $financingTable?->filter('tr')->reduce(static fn (Crawler $tr): bool => $tr->filter('th')->count() === 0)->count() ?? 0;

        if ($crawler->filter('section.blockInfo__section')->count() === 0
            || $itemTable->count() === 0
            || $itemRows->count() !== $parsedItems
            || $expectedItems === null
            || $expectedItems !== $parsedItems
            || $financingTable === null
            || $financingDataRows !== 1
            || $expectedFinancingRows !== $parsedFinancingRows) {
            self::logWarning('Вкладка common-info не подтверждает полный snapshot', [
                'block_sections' => $crawler->filter('section.blockInfo__section')->count(),
                'item_tables' => $itemTable->count(),
                'item_rows' => $itemRows->count(),
                'parsed_items' => $parsedItems,
                'expected_items' => $expectedItems,
                'financing_tables' => $financingTable?->count() ?? 0,
                'financing_data_rows' => $financingDataRows,
                'expected_financing_rows' => $expectedFinancingRows,
                'parsed_financing_rows' => $parsedFinancingRows,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{ikz_year?: int, ikz_customer_code?: string, ikz_plan_number?: string, ikz_plangraph_number?: string, ikz_okpd_class?: string, ikz_okpd_subclass?: string, ikz_okpd_group?: string, ikz_kvr?: string}
     */
    public static function parseIkz(Crawler $crawler, string $term, int $expectedLength = 36): array
    {
        $ikz = self::readDefinitionValue($crawler, $term);

        if ($ikz === null || strlen($ikz) !== $expectedLength) {
            return [];
        }

        return [
            'ikz_year' => (int) substr($ikz, 0, 2),
            'ikz_customer_code' => substr($ikz, 2, 20),
            'ikz_plan_number' => substr($ikz, 22, 4),
            'ikz_plangraph_number' => substr($ikz, 26, 3),
            'ikz_okpd_class' => substr($ikz, 29, 2),
            'ikz_okpd_subclass' => substr($ikz, 31, 1),
            'ikz_okpd_group' => substr($ikz, 32, 1),
            'ikz_kvr' => substr($ikz, 33, 3),
        ];
    }

    /**
     * @return array{name?: string, url?: string}
     */
    public static function parseEtp(Crawler $crawler, string $nameTerm, string $urlTerm): array
    {
        return array_filter([
            'name' => self::readDefinitionValue($crawler, $nameTerm),
            'url' => self::readDefinitionLink($crawler, $urlTerm),
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array{name?: string, email?: string}
     */
    public static function parseContactPerson(Crawler $crawler, string $nameTerm, string $emailTerm): array
    {
        return array_filter([
            'name' => self::readDefinitionValue($crawler, $nameTerm),
            'email' => self::readDefinitionValue($crawler, $emailTerm),
        ], static fn (?string $value): bool => $value !== null);
    }

    public static function classifierScheme(?string $okpd2Code, ?string $ktruCode): string
    {
        return match (true) {
            $okpd2Code !== null => 'okpd2',
            $ktruCode !== null => 'ktru',
            default => 'unknown',
        };
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitClassifierCode(string $rawCode): array
    {
        if ($rawCode === '') {
            return [null, null];
        }

        if (! str_contains($rawCode, '-')) {
            return [$rawCode, null];
        }

        $okpd2Prefix = strstr($rawCode, '-', before_needle: true);

        return [$okpd2Prefix !== false && $okpd2Prefix !== '' ? $okpd2Prefix : null, $rawCode];
    }
}
