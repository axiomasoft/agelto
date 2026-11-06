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
            $financingRows = $this->parseFinancingRows($crawler);
            $items = $this->parseItems($crawler);

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
    private function parseFinancingRows(Crawler $crawler): array
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
        } catch (\Throwable) {
            return [];
        }
    }

    /**
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

            CardDomHelper::itemRows($table)->each(static function (Crawler $tr, int $index) use (&$items): void {
                $cells = $tr->children('td');

                if ($cells->count() < 7) {
                    return;
                }

                $positionNumber = trim($cells->eq(0)->text());
                [$okpd2Code, $ktruCode] = CardDomHelper::splitClassifierCode(trim($cells->eq(1)->text()));

                $items[] = new PurchaseItemRowData(
                    positionNumber: $positionNumber !== '' && ctype_digit($positionNumber) ? (int) $positionNumber : $index + 1,
                    name: trim($cells->eq(2)->text()),
                    characteristics: null,
                    classifierScheme: CardDomHelper::classifierScheme(okpd2Code: $okpd2Code, ktruCode: $ktruCode),
                    classifierCodeOkpd2: $okpd2Code,
                    classifierCodeKtru: $ktruCode,
                    unit: trim($cells->eq(3)->text()) ?: null,
                    quantity: CardDomHelper::normalizeAmount(trim($cells->eq(4)->text())),
                    unitPrice: CardDomHelper::normalizeAmount(trim($cells->eq(5)->text())),
                    totalCost: CardDomHelper::normalizeAmount(trim($cells->eq(6)->text())),
                );
            });

            return $items;
        } catch (\Throwable) {
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
