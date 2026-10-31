<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Details\PartyData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Platform\CollectionGuard;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Enums\Procurement\Platform\Completeness;

/** No source tabs, HTML or platform-specific identifiers. Publication belongs to the finalizer. */
final readonly class ProcurementSnapshot
{
    /**
     * @param  list<PartyData>  $parties
     * @param  list<PurchaseItemRowData>  $items
     * @param  list<FinancingRowData>  $financing
     * @param  list<DocumentReference>  $documents
     * @param  list<SnapshotPart>  $parts
     * @param  list<JournalEntryData>  $events
     */
    public function __construct(
        public SourceIdentity $identity, public string $title, public string $sourceStage,
        public array $parties, public array $items, public array $financing, public array $documents,
        public array $parts, public ?object $extension = null, public ?string $sourceRevision = null,
        public array $events = [], public ?SupplierResultsData $results = null,
    ) {
        foreach (['parties' => PartyData::class, 'items' => PurchaseItemRowData::class, 'financing' => FinancingRowData::class,
            'documents' => DocumentReference::class, 'parts' => SnapshotPart::class, 'events' => JournalEntryData::class] as $field => $type) {
            CollectionGuard::check($this->{$field}, $type);
        }
        $names = array_map(static fn (SnapshotPart $part): string => $part->name, $parts);
        if ($parts === [] || count(array_unique($names)) !== count($names)
            || ($extension !== null && ! (new \ReflectionClass($extension))->isReadOnly())) {
            throw new \InvalidArgumentException('Invalid snapshot parts/extension.');
        }
        foreach ($documents as $document) {
            if ($document->procurement->toArray() !== $identity->toArray()) {
                throw new \InvalidArgumentException('Document identity mismatch.');
            }
        }
    }

    public function complete(): bool
    {
        foreach ($this->parts as $part) {
            if ($part->required && ! in_array($part->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true)) {
                return false;
            }
        }

        return true;
    }
}
