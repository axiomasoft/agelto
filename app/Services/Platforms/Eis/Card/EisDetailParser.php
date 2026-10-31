<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Details\SupplierResultsData;

final readonly class EisDetailParser
{
    public function __construct(
        private CommonInfoParser $common,
        private PartiesParser $parties,
        private DocumentsParser $documentsParser,
        private EventJournalParser $journal,
        private SupplierResultsParser $results,
    ) {}

    public function commonInfo(string $html, bool $strict = false): CommonInfoSnapshot
    {
        $parties = $this->parties->parse(html: $html, strict: $strict);

        return $this->common->parse(html: $html, strict: $strict)->withParties(
            parties: $parties,
            partiesComplete: $this->parties->authorizesReconciliation(html: $html, parties: $parties, strict: $strict),
        );
    }

    public function documents(string $html, bool $strict = false): DocumentsSnapshot
    {
        return $this->documentsParser->parse(html: $html, strict: $strict);
    }

    /** @return list<JournalEntryData> */
    public function eventJournal(string $html, bool $strict = false): array
    {
        return $this->journal->parse(html: $html, strict: $strict);
    }

    public function supplierResults(string $html, bool $strict = false): SupplierResultsData
    {
        return $this->results->parse(html: $html, strict: $strict);
    }
}
