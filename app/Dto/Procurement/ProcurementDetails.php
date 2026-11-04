<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

use App\Dto\Procurement\Details\ParsedProcurementDetails;
use App\Dto\Procurement\Network\AdapterValidationReceipt;

/**
 * Сырой HTML вкладок `common-info`/`documents`/`event-journal`/`supplier-results`,
 * полученный `SourceMethod::collectDetails()`. Разбор вкладок в доменные данные выполняют tab-процессоры.
 */
final readonly class ProcurementDetails
{
    public function __construct(
        public string $registryNumber,
        public string $procedureUrlType,
        public ?string $commonInfoHtml = null,
        public ?string $documentsHtml = null,
        public ?string $eventJournalHtml = null,
        public ?string $supplierResultsHtml = null,
        public ?ParsedProcurementDetails $parsed = null,
        public ?AdapterValidationReceipt $validation = null,
    ) {}

    public function withValidation(?AdapterValidationReceipt $receipt): self
    {
        return new self($this->registryNumber, $this->procedureUrlType, $this->commonInfoHtml, $this->documentsHtml,
            $this->eventJournalHtml, $this->supplierResultsHtml, $this->parsed, $receipt);
    }
}
