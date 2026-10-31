<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Csv;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\ListingFacts;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Platforms\Eis\EisProcedureType;
use App\Services\Platforms\Eis\EisSourceClock;
use Illuminate\Support\Carbon;

final readonly class EisSummaryParser
{
    public function __construct(private EisSourceClock $clock) {}

    /** @param array<string,string> $row */
    public function parse(array $row): ProcurementSummary
    {
        $number = trim($row['Реестровый номер закупки'] ?? '');
        if (str_starts_with($number, '№')) {
            $number = trim(mb_substr($number, 1));
        }
        if (! preg_match('/^[0-9]{1,200}$/D', $number)) {
            throw new \UnexpectedValueException('Invalid EIS identity.');
        }
        $published = $this->date($row['Дата размещения'] ?? '');
        $updated = $this->date($row['Дата обновления'] ?? '', sourceDay: true);
        $dayPrecision = preg_match('/^\d{2}\.\d{2}\.\d{4}$/D', trim($row['Дата обновления'])) === 1;
        $price = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($row['Начальная (максимальная) цена контракта'] ?? ''));
        if ($price === '' || ! preg_match('/^\\d{1,12}(?:\\.\\d{1,2})?$/D', $price)) {
            throw new \UnexpectedValueException('Missing or invalid EIS price.');
        }
        // Include all source columns (not just identities), with stable column order.
        ksort($row, SORT_STRING);
        $procedure = trim($row['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'] ?? '');
        $ikz = trim($row['Идентификационный код закупки (ИКЗ)'] ?? '');
        $customer = trim($row['Наименование Заказчика'] ?? '');
        $fields = [];
        foreach ([
            'procedureType' => 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)',
            'procedureTypeRaw' => 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)',
            'biddingStartsAt' => 'Начало подачи заявок', 'biddingEndsAt' => 'Окончание подачи заявок', 'customerName' => 'Наименование Заказчика',
        ] as $field => $column) {
            if (array_key_exists($column, $row)) {
                $fields[] = $field;
            }
        }

        return new ProcurementSummary(
            new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType('registry:44fz'), new SourceIdentifierValue($number)),
            trim($row['Наименование закупки'] ?? ''), trim($row['Этап закупки'] ?? ''),
            $published, $updated, ContentHash::of(json_encode($row, JSON_THROW_ON_ERROR)),
            initialPrice: $price, currency: $row['Валюта'] ?? null,
            detailLocator: $procedure === '' ? null : new CursorEnvelope('eis.procedure', 1, $procedure),
            updatedAtDayPrecision: $dayPrecision,
            facts: new ListingFacts(EisProcedureType::fromRawLabel($procedure)?->value, $procedure === '' ? null : $procedure,
                $this->optionalDate($row['Начало подачи заявок'] ?? ''), $this->optionalDate($row['Окончание подачи заявок'] ?? ''),
                $customer === '' ? null : $customer,
                $ikz === '' ? null : new EisDetailExtension(ikz: $ikz), $ikz === '' ? [] : ['ikz'], $fields),
            updatedBefore: $dayPrecision ? \DateTimeImmutable::createFromMutable($this->clock->endOfSourceDay(Carbon::instance($updated))) : null,
        );
    }

    private function optionalDate(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        return $this->date(trim($value));
    }

    private function date(string $value, bool $sourceDay = false): \DateTimeImmutable
    {
        foreach (['!d.m.Y H:i:s', '!d.m.Y H:i'] as $format) {
            $date = $this->clock->parseDateTime($format, $value);
            if ($date !== null) {
                return \DateTimeImmutable::createFromMutable($date);
            }
        }
        $date = $sourceDay ? $this->clock->parseDateTime('!d.m.Y', $value) : $this->clock->parseDate('!d.m.Y', $value);
        if ($date === null) {
            throw new \UnexpectedValueException('Invalid EIS date.');
        }

        return \DateTimeImmutable::createFromMutable($date);
    }
}
