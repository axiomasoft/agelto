<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Enums\Procurement\Process\Core\StageCode;
use InvalidArgumentException;

/**
 * Полный типизированный набор правил парсинга стадии `listing`.
 */
final readonly class ListingRuleSetDto implements StageParsingRules
{
    public function __construct(
        public ParsingFormat $format,
        public ListingTransportRule $transport,
        public DateParsingRule $dateParsing,
        public ?CsvDialectRule $csvDialect = null,
        public ?ListingColumnsRule $columns = null,
        public ?JsonListingRule $jsonListing = null,
        public ?RegexPattern $externalIdRegex = null,
        public ?string $detailUrlTemplate = null,
    ) {
        if ($format === ParsingFormat::Csv && ($csvDialect === null || $columns === null)) {
            throw new InvalidArgumentException('CSV listing rules require both csv_dialect and columns.');
        }

        if ($format === ParsingFormat::Html && $columns === null) {
            throw new InvalidArgumentException('HTML listing rules require columns.');
        }

        if ($format === ParsingFormat::Json && $jsonListing === null) {
            throw new InvalidArgumentException('JSON listing rules require json_listing.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Listing;
    }

    public function format(): ParsingFormat
    {
        return $this->format;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $format = ParsingFormat::from(RuleData::nonEmptyString($data, 'format'));
        $transport = ListingTransportRule::fromArray(RuleData::requiredObject($data, 'transport'));
        $dateParsing = DateParsingRule::fromArray(RuleData::requiredObject($data, 'date_parsing'));

        $rawCsvDialect = RuleData::nullableObject($data, 'csv_dialect');
        $rawColumns = RuleData::nullableObject($data, 'columns');
        $rawJsonListing = RuleData::nullableObject($data, 'json_listing');

        return new self(
            format: $format,
            transport: $transport,
            dateParsing: $dateParsing,
            csvDialect: $rawCsvDialect !== null ? CsvDialectRule::fromArray($rawCsvDialect) : null,
            columns: $rawColumns !== null ? ListingColumnsRule::fromArray($rawColumns) : null,
            jsonListing: $rawJsonListing !== null ? JsonListingRule::fromArray($rawJsonListing) : null,
            externalIdRegex: RuleData::nullableRegex($data, 'external_id_regex'),
            detailUrlTemplate: RuleData::nullableString($data, 'detail_url_template'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'transport' => $this->transport->toArray(),
            'date_parsing' => $this->dateParsing->toArray(),
            'csv_dialect' => $this->csvDialect?->toArray(),
            'columns' => $this->columns?->toArray(),
            'json_listing' => $this->jsonListing?->toArray(),
            'external_id_regex' => $this->externalIdRegex?->pattern,
            'detail_url_template' => $this->detailUrlTemplate,
        ];
    }
}
