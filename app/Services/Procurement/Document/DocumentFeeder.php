<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/** Bounded independent producer; admission, fairness, delivery and recovery have one Process owner. */
final readonly class DocumentFeeder implements ReconcileCategory
{
    public function __construct(private DocumentWorkRepository $works, private DocumentRevalidator $revalidator) {}

    public function name(): string
    {
        return 'document_intents';
    }

    public function reconcile(): int
    {
        $created = 0;
        foreach ($this->works->dueRevalidationDocumentIds() as $documentId) {
            $created += (int) ($this->revalidator->allocate(documentId: $documentId) !== null);
        }
        foreach ($this->works->pendingDownloadDocumentIds() as $documentId) {
            $created += $this->works->activate(documentId: $documentId);
        }
        // Missing work only: a blocked/failed item keeps its history and cannot be reset by polling.
        foreach ($this->works->missingPreparationDocumentIds() as $documentId) {
            $created += $this->works->prepare(documentId: $documentId);
        }

        return $created;
    }
}
