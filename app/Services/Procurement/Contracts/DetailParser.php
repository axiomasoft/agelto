<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Details\SupplierResultsData;

interface DetailParser
{
    public function commonInfo(string $html): CommonInfoSnapshot;

    public function documents(string $html): DocumentsSnapshot;

    /** @return list<JournalEntryData> */
    public function eventJournal(string $html): array;

    public function supplierResults(string $html): SupplierResultsData;
}
