<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

/** All pure tab parsers finish before any domain projection is mutated. */
final readonly class ParsedProcurementDetails
{
    /** @param list<JournalEntryData>|null $journalEntries */
    public function __construct(public ?CommonInfoSnapshot $commonInfo, public ?DocumentsSnapshot $documents,
        public ?array $journalEntries, public ?SupplierResultsData $supplierResults) {}
}
