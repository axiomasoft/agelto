<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Enums\Procurement\DetailSnapshotStatus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `common-info` карточки закупки ЕИС (`findings/P4-card-layout.md` §3, живая
 * проба P4.13/P4.14) в {@see CommonInfoData}. Только HTML → DTO, без записи в БД (это
 * `CommonInfoProcessor`). Устойчив к отсутствию отдельных блоков вёрстки — блок отсутствует →
 * соответствующее поле/список остаётся `null`/пустым, парсер не падает. Раскладка полей — не
 * `dt`/`dd` (снято ошибочно `research/10` §3.7), а плоские пары
 * `section.blockInfo__section > span.section__title` + `span.section__info`.
 */
final readonly class CommonInfoParser
{
    private const int IKZ_LENGTH = 36;

    private const string TERM_IKZ = 'Идентификационный код закупки (ИКЗ)';

    private const string TERM_ETP_NAME = 'Наименование электронной площадки в информационно-телекоммуникационной сети «Интернет»';

    private const string TERM_ETP_URL = 'Адрес электронной площадки в информационно-телекоммуникационной сети «Интернет»';

    private const string TERM_CONTACT_NAME = 'Ответственное должностное лицо';

    private const string TERM_CONTACT_EMAIL = 'Адрес электронной почты';

    private const string TERM_FINANCING_TOTAL = 'Финансовое обеспечение закупки (всего)';

    public function parse(string $html): CommonInfoSnapshot
    {
        try {
            $crawler = new Crawler($html);

            $ikz = $this->parseIkz($crawler);
            $etp = $this->parseEtp($crawler);
            $contactPerson = $this->parseContactPerson($crawler);
            $financingRows = $this->parseFinancingRows($crawler);
            $items = $this->parseItems($crawler);

            $this->warnIfBlockInfoSuppressed($crawler, $ikz, $etp, $contactPerson, $financingRows, $items);

            return new CommonInfoSnapshot(
                status: $this->hasCompleteCommonInfoContainer(
                    crawler: $crawler,
                    parsedFinancingRows: count($financingRows),
                    parsedItems: count($items),
                )
                    ? DetailSnapshotStatus::Complete
                    : DetailSnapshotStatus::Incomplete,
                commonInfo: new CommonInfoData(
                    ikzYear: $ikz['ikz_year'] ?? null,
                    ikzCustomerCode: $ikz['ikz_customer_code'] ?? null,
                    ikzPlanNumber: $ikz['ikz_plan_number'] ?? null,
                    ikzPlangraphNumber: $ikz['ikz_plangraph_number'] ?? null,
                    ikzOkpdClass: $ikz['ikz_okpd_class'] ?? null,
                    ikzOkpdSubclass: $ikz['ikz_okpd_subclass'] ?? null,
                    ikzOkpdGroup: $ikz['ikz_okpd_group'] ?? null,
                    ikzKvr: $ikz['ikz_kvr'] ?? null,
                    etpName: $etp['name'] ?? null,
                    etpUrl: $etp['url'] ?? null,
                    contactPersonName: $contactPerson['name'] ?? null,
                    contactPersonEmail: $contactPerson['email'] ?? null,
                    financingRows: $financingRows,
                    items: $items,
                ),
            );
        } catch (\Throwable $exception) {
            Log::warning('Разбор вкладки common-info провалился целиком; snapshot failed', [
                'exception' => $exception->getMessage(),
            ]);

            return new CommonInfoSnapshot(
                status: DetailSnapshotStatus::Failed,
                commonInfo: new CommonInfoData,
            );
        }
    }

    private function hasCompleteCommonInfoContainer(Crawler $crawler, int $parsedFinancingRows, int $parsedItems): bool
    {
        $itemTable = $crawler->filter('div#positionKTRU table.tableBlock');
        $itemRows = $this->itemRows($itemTable);
        $expectedItems = $this->expectedItemCount($itemTable);
        $financingTable = $this->titleSection($crawler, self::TERM_FINANCING_TOTAL)?->filter('table.tableBlock')->first();
        $financingHeaders = $financingTable?->filter('tr.tableBlock__row th')->each(static fn (Crawler $th): string => trim($th->text())) ?? [];
        $expectedFinancingRows = count(array_filter(
            $financingHeaders,
            fn (string $header): bool => $this->parseFiscalYear($header) !== null,
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
            Log::warning('Вкладка common-info не подтверждает полный snapshot', [
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
    private function parseIkz(Crawler $crawler): array
    {
        $ikz = $this->readDefinitionValue($crawler, self::TERM_IKZ);

        if ($ikz === null || strlen($ikz) !== self::IKZ_LENGTH) {
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
     * Имя и адрес площадки живут в ДВУХ раздельных `section` (не в одном `dd` со ссылкой, как
     * предполагала старая раскладка) — имя всегда простой текст, адрес — ссылка внутри
     * `section__info`.
     *
     * @return array{name?: string, url?: string}
     */
    private function parseEtp(Crawler $crawler): array
    {
        $name = $this->readDefinitionValue($crawler, self::TERM_ETP_NAME);

        $urlSection = $this->titleSection($crawler, self::TERM_ETP_URL);
        $urlInfo = $urlSection !== null ? $this->infoNode($urlSection) : null;
        $link = $urlInfo?->filter('a');
        $url = $link !== null && $link->count() > 0 ? $link->attr('href') : null;

        return array_filter([
            'name' => $name,
            'url' => $url,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array{name?: string, email?: string}
     */
    private function parseContactPerson(Crawler $crawler): array
    {
        $name = $this->readDefinitionValue($crawler, self::TERM_CONTACT_NAME);
        $email = $this->readDefinitionValue($crawler, self::TERM_CONTACT_EMAIL);

        return array_filter([
            'name' => $name,
            'email' => $email,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * Раскладка «год — колонка, а не строка»: заголовки таблицы («На 2025 год, ₽», …, «Всего, ₽»)
     * и ровно одна строка значений в том же порядке колонок. Транспонируем в список строк по
     * году. Колонка «Всего» — производная (пропускается). Колонка «Последующие годы» не несёт
     * дискретного года — `FinancingRowData::fiscalYear` в контракте DTO не `nullable`
     * (форма DTO вне Scope item'а), поэтому этот бакет сейчас не заполняется (см. Pending Work).
     *
     * @return list<FinancingRowData>
     */
    private function parseFinancingRows(Crawler $crawler): array
    {
        try {
            $section = $this->titleSection($crawler, self::TERM_FINANCING_TOTAL);

            if ($section === null) {
                return [];
            }

            $table = $section->filter('table.tableBlock')->first();

            if ($table->count() === 0) {
                return [];
            }

            $headers = $table->filter('tr.tableBlock__row th')->each(static fn (Crawler $th): string => trim($th->text()));
            $dataRow = $table->filter('tr')->reduce(static fn (Crawler $tr): bool => $tr->filter('th')->count() === 0);

            if ($dataRow->count() === 0) {
                return [];
            }

            $values = $dataRow->first()->filter('td')->each(static fn (Crawler $td): string => trim($td->text()));

            $rows = [];

            foreach ($headers as $index => $header) {
                $year = $this->parseFiscalYear($header);

                if ($year === null) {
                    continue;
                }

                $amount = $this->normalizeAmount($values[$index] ?? '');

                if ($amount === null) {
                    continue;
                }

                $rows[] = new FinancingRowData(fiscalYear: $year, amount: $amount);
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseFiscalYear(string $header): ?int
    {
        return preg_match('/(\d{4})\s*год/u', $header, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * positionNumber — устойчивый ключ upsert. Когда служебная первая ячейка пуста,
     * номер определяется порядком позиций, без раскрываемых truInfo-строк заказчика.
     * Повреждённая строка позиции остаётся в исходном порядке: пропуск выявляет проверка
     * полноты и заявленный источником итог, а не скрытая перенумерация оставшихся строк.
     *
     * @return list<PurchaseItemRowData>
     */
    private function parseItems(Crawler $crawler): array
    {
        try {
            $table = $crawler->filter('div#positionKTRU table.tableBlock');

            if ($table->count() === 0) {
                return [];
            }

            $items = [];

            $this->itemRows($table)->each(function (Crawler $tr, int $index) use (&$items): void {
                $cells = $tr->children('td');

                if ($cells->count() < 7) {
                    return;
                }

                $positionNumber = trim($cells->eq(0)->text());
                [$okpd2Code, $ktruCode] = $this->splitClassifierCode(trim($cells->eq(1)->text()));

                $items[] = new PurchaseItemRowData(
                    positionNumber: $positionNumber !== '' && ctype_digit($positionNumber) ? (int) $positionNumber : $index + 1,
                    name: trim($cells->eq(2)->text()),
                    characteristics: null,
                    classifierScheme: $this->classifierScheme(okpd2Code: $okpd2Code, ktruCode: $ktruCode),
                    classifierCodeOkpd2: $okpd2Code,
                    classifierCodeKtru: $ktruCode,
                    unit: trim($cells->eq(3)->text()) ?: null,
                    quantity: $this->normalizeAmount(trim($cells->eq(4)->text())),
                    unitPrice: $this->normalizeAmount(trim($cells->eq(5)->text())),
                    totalCost: $this->normalizeAmount(trim($cells->eq(6)->text())),
                );
            });

            return $items;
        } catch (\Throwable) {
            return [];
        }
    }

    private function itemRows(Crawler $table): Crawler
    {
        if ($table->count() === 0) {
            return $table;
        }

        return $table->children('tbody')->children('tr')->reduce(static function (Crawler $row): bool {
            if (preg_match('/(?:^|\s)truInfo_\d+(?:\s|$)/', (string) $row->attr('class')) === 1) {
                return false;
            }

            if ($row->children()->count() === 0) {
                return false;
            }

            $cells = $row->children('td');

            // A dangling empty spacer is produced when the source's nested hidden rows are repaired.
            return ! ($cells->count() === 1
                && trim($cells->text()) === ''
                && preg_match('/(?:^|\s)pt-0(?:\s|$)/', (string) $cells->attr('class')) === 1);
        });
    }

    private function expectedItemCount(Crawler $tables): ?int
    {
        if ($tables->count() === 0) {
            return null;
        }

        $totals = $tables->children('tfoot')->filter('.tableBlock__resultTitle');

        if ($totals->count() !== $tables->count()) {
            return null;
        }

        $expected = 0;

        foreach ($totals as $node) {
            if (preg_match('/^Итого\s*\((\d+)\s+запис(?:ь|и|ей)\)\s*:/u', trim($node->textContent), $match) !== 1) {
                return null;
            }

            $expected += (int) $match[1];
        }

        return $expected;
    }

    /**
     * `classifier_scheme` — `NOT NULL text` (`2026_07_04_100015_create_prc_items_table.php`),
     * поэтому при отсутствии обоих кодов пишем явное `unknown`, а не вводящее в заблуждение
     * `ktru` без реального КТРУ-кода.
     */
    private function classifierScheme(?string $okpd2Code, ?string $ktruCode): string
    {
        return match (true) {
            $okpd2Code !== null => 'okpd2',
            $ktruCode !== null => 'ktru',
            default => 'unknown',
        };
    }

    /**
     * Снятая карточка несёт в ячейке РОВНО один код, не пару «ОКПД2 / КТРУ» через слэш (это
     * предполагала старая раскладка, `research/10` §3.7 её не сверял). КТРУ отличим по
     * дефис-суффиксу поверх префикса ОКПД2 (`database/factories/Reference/KtruFactory.php` —
     * `##.##.##.###-#####`); без дефиса ячейка несёт голый код ОКПД2.
     *
     * @return array{0: ?string, 1: ?string} [ОКПД2, КТРУ]
     */
    private function splitClassifierCode(string $rawCode): array
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

    private function normalizeAmount(string $rawAmount): ?string
    {
        if ($rawAmount === '') {
            return null;
        }

        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', $rawAmount);
        $normalized = str_replace(',', '.', (string) $normalized);

        return is_numeric($normalized) ? $normalized : null;
    }

    private function readDefinitionValue(Crawler $crawler, string $term): ?string
    {
        $section = $this->titleSection($crawler, $term);
        $info = $section !== null ? $this->infoNode($section) : null;

        if ($info === null) {
            return null;
        }

        $value = trim($info->text());

        return $value !== '' ? $value : null;
    }

    /**
     * Единственный носитель поиска «раздел по заголовку» (Implementation Rules item'а P4.14 —
     * общий помощник меняется ОДИН раз, не тремя локальными костылями): ищет
     * `span.section__title` с точным текстом `$term` и возвращает ближайший `section`-предок.
     */
    private function titleSection(Crawler $crawler, string $term): ?Crawler
    {
        try {
            $title = $crawler
                ->filter('span.section__title')
                ->reduce(static fn (Crawler $span): bool => trim($span->text()) === $term);

            if ($title->count() === 0) {
                return null;
            }

            $section = $title->ancestors()->first();

            return $section->count() > 0 ? $section : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function infoNode(Crawler $section): ?Crawler
    {
        $info = $section->filter('span.section__info')->first();

        return $info->count() > 0 ? $info : null;
    }

    /**
     * Тихий отказ (`research/10-p4-remediation.md` §3.7, Д-7): страница несёт блоки
     * `blockInfo__section`, а не распознано вообще ни одного поля/строки — признак
     * разошедшейся вёрстки, обязан быть виден в логе (легитимное отсутствие ОТДЕЛЬНЫХ
     * блоков — штатный случай, докблок класса, — здесь не срабатывает: порог именно «ничего»).
     *
     * @param  array<string, int|string>  $ikz
     * @param  array<string, string>  $etp
     * @param  array<string, string>  $contactPerson
     * @param  list<FinancingRowData>  $financingRows
     * @param  list<PurchaseItemRowData>  $items
     */
    private function warnIfBlockInfoSuppressed(Crawler $crawler, array $ikz, array $etp, array $contactPerson, array $financingRows, array $items): void
    {
        if ($ikz !== [] || $etp !== [] || $contactPerson !== [] || $financingRows !== [] || $items !== []) {
            return;
        }

        $sections = $crawler->filter('section.blockInfo__section')->count();

        if ($sections > 0) {
            Log::warning('На вкладке common-info есть блоки blockInfo, но не распознано ни одного поля', [
                'sections' => $sections,
            ]);
        }
    }
}
