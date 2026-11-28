<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Enums\Procurement\Parsing\ParsingFormat;
use App\Enums\Procurement\Process\Core\StageCode;
use InvalidArgumentException;

/**
 * Полный типизированный набор правил парсинга стадии `detail`.
 */
final readonly class DetailRuleSetDto implements StageParsingRules
{
    public function __construct(
        public ParsingFormat $format,
        public ?CardRoutesRule $routes = null,
        public ?CardEnvelopeRule $envelope = null,
        public ?CardDomRule $dom = null,
        public ?CommonInfoRule $commonInfo = null,
        public ?PartiesRule $parties = null,
        public ?DocumentsRule $documents = null,
        public ?EventJournalRule $eventJournal = null,
        public ?SupplierResultsRule $supplierResults = null,
        public ?JsonDetailRule $jsonDetail = null,
    ) {
        if ($format === ParsingFormat::Csv) {
            throw new InvalidArgumentException('Detail rules do not support CSV format.');
        }

        if ($format === ParsingFormat::Json && $jsonDetail === null) {
            throw new InvalidArgumentException('JSON detail rules require json_detail.');
        }

        if ($format === ParsingFormat::Html && $envelope === null && $dom === null) {
            throw new InvalidArgumentException('HTML detail rules require envelope or dom.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Detail;
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

        $rawRoutes = RuleData::nullableObject($data, 'routes');
        $rawEnvelope = RuleData::nullableObject($data, 'envelope');
        $rawDom = RuleData::nullableObject($data, 'dom');
        $rawCommonInfo = RuleData::nullableObject($data, 'common_info');
        $rawParties = RuleData::nullableObject($data, 'parties');
        $rawDocuments = RuleData::nullableObject($data, 'documents');
        $rawEventJournal = RuleData::nullableObject($data, 'event_journal');
        $rawSupplierResults = RuleData::nullableObject($data, 'supplier_results');
        $rawJsonDetail = RuleData::nullableObject($data, 'json_detail');

        return new self(
            format: $format,
            routes: $rawRoutes !== null ? CardRoutesRule::fromArray($rawRoutes) : null,
            envelope: $rawEnvelope !== null ? CardEnvelopeRule::fromArray($rawEnvelope) : null,
            dom: $rawDom !== null ? CardDomRule::fromArray($rawDom) : null,
            commonInfo: $rawCommonInfo !== null ? CommonInfoRule::fromArray($rawCommonInfo) : null,
            parties: $rawParties !== null ? PartiesRule::fromArray($rawParties) : null,
            documents: $rawDocuments !== null ? DocumentsRule::fromArray($rawDocuments) : null,
            eventJournal: $rawEventJournal !== null ? EventJournalRule::fromArray($rawEventJournal) : null,
            supplierResults: $rawSupplierResults !== null ? SupplierResultsRule::fromArray($rawSupplierResults) : null,
            jsonDetail: $rawJsonDetail !== null ? JsonDetailRule::fromArray($rawJsonDetail) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'routes' => $this->routes?->toArray(),
            'envelope' => $this->envelope?->toArray(),
            'dom' => $this->dom?->toArray(),
            'common_info' => $this->commonInfo?->toArray(),
            'parties' => $this->parties?->toArray(),
            'documents' => $this->documents?->toArray(),
            'event_journal' => $this->eventJournal?->toArray(),
            'supplier_results' => $this->supplierResults?->toArray(),
            'json_detail' => $this->jsonDetail?->toArray(),
        ];
    }
}
