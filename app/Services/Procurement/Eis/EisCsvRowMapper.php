<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\EisProcedureType;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Contracts\ListingSchemaAware;
use Illuminate\Support\Carbon;

/**
 * Строка CSV-экспорта ЕИС (заголовки — русские названия колонок, `research/eis-csv-recon.md`)
 * → нормализованный `ProcurementSnapshotData`. Вся раскладка колонок — здесь; статус НЕ
 * резолвится (сырой `source_stage` резолвит `StatusMappingService` внутри `ProcurementIngestProcessor`).
 */
final readonly class EisCsvRowMapper implements ListingRowMapper, ListingSchemaAware
{
    public function __construct(private EisSourceClock $clock = new EisSourceClock) {}

    private const string REGISTRY_NUMBER_COLUMN = 'Реестровый номер закупки';

    private const string PROCUREMENT_METHOD_COLUMN = 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)';

    private const string TITLE_COLUMN = 'Наименование закупки';

    private const string STAGE_COLUMN = 'Этап закупки';

    private const string IKZ_COLUMN = 'Идентификационный код закупки (ИКЗ)';

    private const string INITIAL_PRICE_COLUMN = 'Начальная (максимальная) цена контракта';

    private const string CURRENCY_COLUMN = 'Валюта';

    private const string PUBLISHED_AT_COLUMN = 'Дата размещения';

    private const string SOURCE_UPDATED_AT_COLUMN = 'Дата обновления';

    private const string BIDDING_STARTS_AT_COLUMN = 'Начало подачи заявок';

    private const string BIDDING_ENDS_AT_COLUMN = 'Окончание подачи заявок';

    private const string CUSTOMER_NAME_COLUMN = 'Наименование Заказчика';

    private const string FZ44_REGISTRY_CODE = 'fz44';

    /**
     * Колонки, без которых `map()` возвращает `null` для любой строки (номер, даты размещения
     * и обновления). Остальные колонки необязательны: их отсутствие даёт неполный снимок, но
     * не теряет закупку. Эталон healthcheck (`listing_csv.required_headers`) шире — он про
     * качество данных, а не про возможность разбора, поэтому здесь не используется.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return [self::REGISTRY_NUMBER_COLUMN, self::PUBLISHED_AT_COLUMN, self::SOURCE_UPDATED_AT_COLUMN];
    }

    /**
     * Префикс «№» (U+2116) перед номером реестровой закупки — живая проба P4.9
     * (`findings/P4-csv-headers.md`): 500/500 строк блока несут его в значении, площадка не
     * документировала это нигде. Снимается ДО сохранения — резолв карточки (`EisCardUrlBuilder::
     * buildUrl()`) подставляет `registry_number` в `regNumber` query-параметр карточки, а
     * образцы `artifacts/eis-registry-settings.json` `_observed` несут номер БЕЗ префикса.
     */
    private const string REGISTRY_NUMBER_PREFIX = '№';

    public function map(array $row, Crawler $crawler): ?ProcurementSnapshotData
    {
        $registryNumber = $this->stripRegistryNumberPrefix(trim($row[self::REGISTRY_NUMBER_COLUMN] ?? ''));

        if ($registryNumber === '') {
            return null;
        }

        $publishedAt = $this->parseDate(value: $row[self::PUBLISHED_AT_COLUMN] ?? null);
        $sourceUpdatedAt = $this->parseSourceUpdatedAt(value: $row[self::SOURCE_UPDATED_AT_COLUMN] ?? null);

        if ($publishedAt === null || $sourceUpdatedAt === null) {
            return null;
        }

        [$procedureType, $procedureTypeRaw] = $this->mapProcedureType(row: $row, crawler: $crawler);

        return new ProcurementSnapshotData(
            registryId: $crawler->registry_id,
            registryNumber: $registryNumber,
            title: trim($row[self::TITLE_COLUMN] ?? ''),
            sourceStage: trim($row[self::STAGE_COLUMN] ?? ''),
            publishedAt: $publishedAt,
            sourceUpdatedAt: $sourceUpdatedAt['value'],
            sourceUpdatedAtHasDayPrecision: $sourceUpdatedAt['hasDayPrecision'],
            ikz: $this->nullableString(value: $row[self::IKZ_COLUMN] ?? null),
            procedureType: $procedureType,
            procedureTypeRaw: $procedureTypeRaw,
            initialPrice: $this->mapPrice(value: $row[self::INITIAL_PRICE_COLUMN] ?? null),
            currency: $this->nullableString(value: $row[self::CURRENCY_COLUMN] ?? null),
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

        $registry = $crawler->registry;
        $isFz44 = $registry?->code === self::FZ44_REGISTRY_CODE;
        $procedureType = $isFz44 ? EisProcedureType::fromRawLabel(raw: $raw)?->value : null;

        return [$procedureType, $raw];
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

        foreach (['!d.m.Y H:i:s', '!d.m.Y H:i'] as $format) {
            $date = $this->clock->parseDateTime(format: $format, value: $value);

            if ($date !== null) {
                return $date;
            }
        }

        return $this->clock->parseDate(format: '!d.m.Y', value: $value);
    }

    /**
     * @return array{value: Carbon, hasDayPrecision: bool}|null
     */
    private function parseSourceUpdatedAt(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['!d.m.Y H:i:s', '!d.m.Y H:i'] as $format) {
            $date = $this->clock->parseDateTime(format: $format, value: $value);

            if ($date !== null) {
                return ['value' => $date, 'hasDayPrecision' => false];
            }
        }

        // Дата без времени — это целые сутки источника. Храним их НАЧАЛО как момент
        // (полночь по часовому поясу источника → UTC), а не «полночь UTC того же числа»: тогда
        // сравнение с любым моментом не зависит от пояса — сутки D закончились ровно в
        // `value + 1 день` (см. `ProcurementIngestProcessor::isOlderThanHead`).
        $date = $this->clock->parseDateTime(format: '!d.m.Y', value: $value);

        return $date === null ? null : ['value' => $date, 'hasDayPrecision' => true];
    }

    private function stripRegistryNumberPrefix(string $registryNumber): string
    {
        return str_starts_with($registryNumber, self::REGISTRY_NUMBER_PREFIX)
            ? trim(mb_substr($registryNumber, mb_strlen(self::REGISTRY_NUMBER_PREFIX)))
            : $registryNumber;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
