<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Csv;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingFacts;
use App\Dto\Procurement\Parsing\Rules\ListingRuleSetDto;
use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierValue;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Platforms\Eis\EisProcedureType;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;
use Illuminate\Support\Carbon;

final readonly class EisSummaryParser
{
    public function __construct(
        private EisSourceClock $clock,
        private ?ListingRuleSetDto $rules = null,
        private ?TabularSummaryMapper $mapper = null,
    ) {}

    /** @param array<string,string> $row */
    public function parse(array $row): ProcurementSummary
    {
        $rules = $this->rules ?? EisDefaultParsingRules::listing();
        $mappedItem = null;
        if ($this->mapper !== null && $rules->columns !== null) {
            $mapped = $this->mapper->map(array_keys($row), [array_values($row)], $rules->columns);
            $mappedItem = $mapped['items'][0] ?? null;
        }

        $number = trim((string) ($mappedItem['externalId'] ?? $row['Реестровый номер закупки'] ?? ''));
        if (str_starts_with($number, '№')) {
            $number = trim(mb_substr($number, 1));
        }
        if (preg_match('/^[0-9]{1,200}$/D', $number) !== 1) {
            throw new \UnexpectedValueException('Invalid EIS identity.');
        }

        $publishedRaw = (string) ($mappedItem['publishedAt'] ?? $row['Дата размещения'] ?? '');
        $updatedRaw = (string) ($mappedItem['updatedAt'] ?? $row['Дата обновления'] ?? '');
        $published = $this->date($publishedRaw, formats: $rules->dateParsing->formats);
        $updated = $this->date($updatedRaw, sourceDay: true, formats: $rules->dateParsing->formats);
        $dayPrecision = preg_match('/^\d{2}\.\d{2}\.\d{4}$/D', trim($updatedRaw)) === 1;
        $priceRaw = trim((string) ($mappedItem['initialPrice'] ?? $row['Начальная (максимальная) цена контракта'] ?? ''));
        $price = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $priceRaw);
        if ($price !== '' && ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $price)) {
            throw new \UnexpectedValueException('Invalid EIS price.');
        }
        // Include all source columns (not just identities), with stable column order.
        ksort($row, SORT_STRING);
        $procedure = trim((string) ($mappedItem['procedureTypeRaw'] ?? $row['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'] ?? ''));
        $ikz = trim((string) ($mappedItem['ikz'] ?? $row['Идентификационный код закупки (ИКЗ)'] ?? ''));
        $customer = trim((string) ($mappedItem['customerName'] ?? $row['Наименование Заказчика'] ?? ''));
        $biddingStartsRaw = (string) ($mappedItem['biddingStartsAt'] ?? $row['Начало подачи заявок'] ?? '');
        $biddingEndsRaw = (string) ($mappedItem['biddingEndsAt'] ?? $row['Окончание подачи заявок'] ?? '');

        $fields = [];
        foreach ([
            'procedureType' => ['column' => 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)', 'mapped' => 'procedureTypeRaw'],
            'procedureTypeRaw' => ['column' => 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)', 'mapped' => 'procedureTypeRaw'],
            'biddingStartsAt' => ['column' => 'Начало подачи заявок', 'mapped' => 'biddingStartsAt'],
            'biddingEndsAt' => ['column' => 'Окончание подачи заявок', 'mapped' => 'biddingEndsAt'],
            'customerName' => ['column' => 'Наименование Заказчика', 'mapped' => 'customerName'],
        ] as $field => $spec) {
            if (array_key_exists($spec['column'], $row) || ($mappedItem !== null && array_key_exists($spec['mapped'], $mappedItem))) {
                $fields[] = $field;
            }
        }

        $extensionValues = $ikz === '' ? [] : ['ikz' => $ikz];

        return new ProcurementSummary(
            new SourceIdentity(new PlatformCode('eis'), new SourceIdentifierType('registry:44fz'), new SourceIdentifierValue($number)),
            trim((string) ($mappedItem['title'] ?? $row['Наименование закупки'] ?? '')),
            trim((string) ($mappedItem['sourceStage'] ?? $row['Этап закупки'] ?? '')),
            $published,
            $updated,
            ContentHash::of(json_encode($row, JSON_THROW_ON_ERROR)),
            initialPrice: $price === '' ? null : $price,
            currency: $mappedItem['currency'] ?? $row['Валюта'] ?? null,
            detailLocator: $procedure === '' ? null : new CursorEnvelope('eis.procedure', 1, $procedure),
            updatedAtDayPrecision: $dayPrecision,
            facts: new ListingFacts(
                EisProcedureType::fromRawLabel($procedure)?->value,
                $procedure === '' ? null : $procedure,
                $this->optionalDate($biddingStartsRaw, $rules->dateParsing->formats),
                $this->optionalDate($biddingEndsRaw, $rules->dateParsing->formats),
                $customer === '' ? null : $customer,
                $extensionValues === [] ? null : new EisDetailExtension(ikz: $extensionValues['ikz']),
                $extensionValues === [] ? [] : ['ikz'],
                $fields,
            ),
            updatedBefore: $dayPrecision ? \DateTimeImmutable::createFromMutable($this->clock->endOfSourceDay(Carbon::instance($updated))) : null,
        );
    }

    /**
     * @param  non-empty-list<string>  $formats
     */
    private function optionalDate(string $value, array $formats): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        return $this->date(trim($value), formats: $formats);
    }

    /**
     * @param  non-empty-list<string>  $formats
     */
    private function date(string $value, bool $sourceDay = false, array $formats = ['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y']): \DateTimeImmutable
    {
        foreach ($formats as $format) {
            if ($format === '!d.m.Y') {
                continue;
            }
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
