<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Enums\Procurement\DetailSnapshotStatus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор вкладки `common-info` карточки закупки ЕИС в {@see CommonInfoData}.
 * Только HTML → DTO, без записи в БД (это
 * `CommonInfoProcessor`). Устойчив к отсутствию отдельных блоков вёрстки — блок отсутствует →
 * соответствующее поле/список остаётся `null`/пустым, парсер не падает.
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

    public function parse(string $html, bool $strict = false): CommonInfoSnapshot
    {
        try {
            $crawler = new Crawler($html);

            $ikz = CardDomHelper::parseIkz($crawler, self::TERM_IKZ, self::IKZ_LENGTH);
            $etp = CardDomHelper::parseEtp($crawler, self::TERM_ETP_NAME, self::TERM_ETP_URL);
            $contactPerson = CardDomHelper::parseContactPerson($crawler, self::TERM_CONTACT_NAME, self::TERM_CONTACT_EMAIL);
            $financingRows = $this->parseFinancingRows(crawler: $crawler, strict: $strict);
            $items = $this->parseItems(crawler: $crawler, strict: $strict);

            $this->warnIfBlockInfoSuppressed($crawler, $ikz, $etp, $contactPerson, $financingRows, $items);

            return new CommonInfoSnapshot(
                status: CardDomHelper::hasCompleteCommonInfoContainer(
                    crawler: $crawler,
                    financingTerm: self::TERM_FINANCING_TOTAL,
                    parsedFinancingRows: count($financingRows),
                    parsedItems: count($items),
                )
                    ? DetailSnapshotStatus::Complete
                    : DetailSnapshotStatus::Incomplete,
                commonInfo: new CommonInfoData(
                    extension: new EisDetailExtension(
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
                        hasCommonInfo: true,
                    ),
                    contactPersonName: $contactPerson['name'] ?? null,
                    contactPersonEmail: $contactPerson['email'] ?? null,
                    financingRows: $financingRows,
                    items: $items,
                ),
            );
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }
            CardDomHelper::logWarning('Разбор вкладки common-info провалился целиком; snapshot failed', [
                'exception' => $exception->getMessage(),
            ]);

            return new CommonInfoSnapshot(
                status: DetailSnapshotStatus::Failed,
                commonInfo: new CommonInfoData,
            );
        }
    }

    /**
     * @return list<FinancingRowData>
     */
    private function parseFinancingRows(Crawler $crawler, bool $strict): array
    {
        try {
            $section = CardDomHelper::titleSection($crawler, self::TERM_FINANCING_TOTAL);

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
                $year = CardDomHelper::parseFiscalYear($header);

                if ($year === null) {
                    continue;
                }

                $amount = CardDomHelper::normalizeAmount($values[$index] ?? '');

                if ($amount === null) {
                    continue;
                }

                $rows[] = new FinancingRowData(fiscalYear: $year, amount: $amount);
            }

            return $rows;
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * @return list<PurchaseItemRowData>
     */
    private function parseItems(Crawler $crawler, bool $strict): array
    {
        try {
            $table = CardDomHelper::itemTables($crawler);

            if ($table->count() === 0) {
                return [];
            }

            $headerCount = $table->first()->children('thead')->children('tr')->first()->children()->count();
            $expectedColumns = match ($headerCount) {
                5 => 5,
                6 => 6,
                default => 7,
            };
            $rows = [];
            $hasExplicitPosition = false;

            CardDomHelper::itemRows($table)->each(static function (Crawler $tr) use (&$rows, &$hasExplicitPosition, $expectedColumns): void {
                $cells = $tr->children('td');

                if ($cells->count() < $expectedColumns) {
                    return;
                }

                $positionNumber = trim($cells->eq(0)->text());
                $explicitPosition = $positionNumber !== '' && ctype_digit($positionNumber) ? (int) $positionNumber : null;
                $hasExplicitPosition = $hasExplicitPosition || $explicitPosition !== null;
                [$okpd2Code, $ktruCode] = CardDomHelper::splitClassifierCode(trim($cells->eq(1)->text()));

                $rows[] = [
                    'explicitPosition' => $explicitPosition,
                    'name' => trim($cells->eq(2)->text()),
                    'scheme' => CardDomHelper::classifierScheme(okpd2Code: $okpd2Code, ktruCode: $ktruCode),
                    'okpd2' => $okpd2Code,
                    'ktru' => $ktruCode,
                    'unit' => $expectedColumns === 5 ? null : (trim($cells->eq(3)->text()) ?: null),
                    'quantity' => match ($expectedColumns) {
                        5 => CardDomHelper::normalizeAmount(trim($cells->eq(3)->text())),
                        6 => null,
                        default => CardDomHelper::normalizeAmount(trim($cells->eq(4)->text())),
                    },
                    'unitPrice' => match ($expectedColumns) {
                        5 => null,
                        6 => CardDomHelper::normalizeAmount(trim($cells->eq(4)->text())),
                        default => CardDomHelper::normalizeAmount(trim($cells->eq(5)->text())),
                    },
                    'totalCost' => CardDomHelper::normalizeAmount(trim($cells->eq($expectedColumns - 1)->text())),
                ];
            });

            if (! $hasExplicitPosition && count($rows) > 1) {
                usort($rows, static fn (array $a, array $b): int => [
                    $a['ktru'] ?? '',
                    $a['okpd2'] ?? '',
                    $a['name'],
                    $a['totalCost'] ?? '',
                    $a['unitPrice'] ?? '',
                    $a['quantity'] ?? '',
                ] <=> [
                    $b['ktru'] ?? '',
                    $b['okpd2'] ?? '',
                    $b['name'],
                    $b['totalCost'] ?? '',
                    $b['unitPrice'] ?? '',
                    $b['quantity'] ?? '',
                ]);
            }

            $items = [];
            foreach ($rows as $index => $row) {
                $items[] = new PurchaseItemRowData(
                    positionNumber: $row['explicitPosition'] ?? ($index + 1),
                    name: $row['name'],
                    characteristics: null,
                    classifierScheme: $row['scheme'],
                    classifierCodeOkpd2: $row['okpd2'],
                    classifierCodeKtru: $row['ktru'],
                    unit: $row['unit'],
                    quantity: $row['quantity'],
                    unitPrice: $row['unitPrice'],
                    totalCost: $row['totalCost'],
                );
            }

            return $items;
        } catch (\Throwable $exception) {
            if ($strict) {
                throw $exception;
            }

            return [];
        }
    }

    /**
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

        CardDomHelper::warnIfBlockInfoSuppressed($crawler, 'На вкладке common-info есть блоки blockInfo, но не распознано ни одного поля');
    }
}
