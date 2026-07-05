<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Crawler\EisProcedureType;
use App\Models\Crawler\Crawler;
use App\Services\Crawler\Contracts\ListingRowMapper;
use Illuminate\Support\Carbon;

/**
 * Строка CSV-экспорта ЕИС (заголовки — русские названия колонок, `research/eis-csv-recon.md`)
 * → нормализованный `ProcurementSnapshotData`. Вся раскладка колонок — здесь; статус НЕ
 * резолвится (сырой `source_status` резолвит `StatusMappingService` внутри `ProcurementIngestProcessor`).
 */
final readonly class EisCsvRowMapper implements ListingRowMapper
{
    private const string REGISTRY_NUMBER_COLUMN = 'Реестровый номер закупки';

    private const string PROCUREMENT_METHOD_COLUMN = 'Способ определения поставщика';

    private const string TITLE_COLUMN = 'Наименование закупки';

    private const string STAGE_COLUMN = 'Этап закупки';

    private const string IKZ_COLUMN = 'Идентификационный код закупки (ИКЗ)';

    private const string INITIAL_PRICE_COLUMN = 'НМЦК';

    private const string CURRENCY_COLUMN = 'Валюта';

    private const string OKPD2_COLUMN = 'ОКПД2';

    private const string PUBLISHED_AT_COLUMN = 'Дата размещения';

    private const string SOURCE_UPDATED_AT_COLUMN = 'Дата обновления';

    private const string BIDDING_STARTS_AT_COLUMN = 'Начало подачи заявок';

    private const string BIDDING_ENDS_AT_COLUMN = 'Окончание подачи заявок';

    private const string CUSTOMER_NAME_COLUMN = 'Наименование заказчика';

    private const string FZ44_REGISTRY_CODE = 'fz44';

    public function map(array $row, Crawler $crawler): ?ProcurementSnapshotData
    {
        $sourceNumber = trim($row[self::REGISTRY_NUMBER_COLUMN] ?? '');

        if ($sourceNumber === '') {
            return null;
        }

        $publishedAt = $this->parseDate(value: $row[self::PUBLISHED_AT_COLUMN] ?? null);
        $sourceUpdatedAt = $this->parseDate(value: $row[self::SOURCE_UPDATED_AT_COLUMN] ?? null);

        if ($publishedAt === null || $sourceUpdatedAt === null) {
            return null;
        }

        [$procedureType, $procedureTypeRaw] = $this->mapProcedureType(row: $row, crawler: $crawler);
        [$okpd2Code, $okpd2Codes] = $this->mapOkpd2(value: $row[self::OKPD2_COLUMN] ?? null);

        return new ProcurementSnapshotData(
            registryId: $crawler->registry_id,
            sourceNumber: $sourceNumber,
            title: trim($row[self::TITLE_COLUMN] ?? ''),
            sourceStatus: trim($row[self::STAGE_COLUMN] ?? ''),
            publishedAt: $publishedAt,
            sourceUpdatedAt: $sourceUpdatedAt,
            ikz: $this->nullableString(value: $row[self::IKZ_COLUMN] ?? null),
            procedureType: $procedureType,
            procedureTypeRaw: $procedureTypeRaw,
            customerOrgId: null,
            initialPrice: $this->mapPrice(value: $row[self::INITIAL_PRICE_COLUMN] ?? null),
            currency: $this->nullableString(value: $row[self::CURRENCY_COLUMN] ?? null),
            okpd2Code: $okpd2Code,
            okpd2Codes: $okpd2Codes,
            regionCode: null,
            biddingStartsAt: $this->parseDate(value: $row[self::BIDDING_STARTS_AT_COLUMN] ?? null),
            biddingEndsAt: $this->parseDate(value: $row[self::BIDDING_ENDS_AT_COLUMN] ?? null),
            attributes: $this->mapAttributes(row: $row),
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: ?string, 1: ?string} [procedureType, procedureTypeRaw]
     */
    private function mapProcedureType(array $row, Crawler $crawler): array
    {
        $raw = $this->nullableString(value: $row[self::PROCUREMENT_METHOD_COLUMN] ?? null);

        if ($raw === null) {
            return [null, null];
        }

        $isFz44 = $crawler->registry->code === self::FZ44_REGISTRY_CODE;
        $procedureType = $isFz44 ? EisProcedureType::fromRawLabel(raw: $raw)?->value : null;

        return [$procedureType, $raw];
    }

    /**
     * @return array{0: ?string, 1: ?list<string>} [okpd2Code, okpd2Codes]
     */
    private function mapOkpd2(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [null, null];
        }

        $codes = array_values(array_filter(
            array_map(static fn (string $code): string => trim($code), preg_split('/[,;]/', $value) ?: []),
            static fn (string $code): bool => $code !== '',
        ));

        if ($codes === []) {
            return [null, null];
        }

        return [$codes[0], $codes];
    }

    private function mapPrice(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($value));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function mapAttributes(array $row): ?array
    {
        $customerName = $this->nullableString(value: $row[self::CUSTOMER_NAME_COLUMN] ?? null);

        if ($customerName === null) {
            return null;
        }

        return ['customer_name' => $customerName];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
