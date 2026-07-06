<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Details\DocumentData;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Facades\DB;

/**
 * DTO → `prc_documents` для вкладки `documents`: upsert по `(procurement_id, external_id)`
 * (идемпотентный — статус не сбрасывается на повторном прогоне), документы снятые с карточки
 * (`external_id` больше не встречается) → `removed_at` + событие `document_removed`.
 * Файл (`prc_document_versions`) — вне этого класса, см. {@see DocumentDownloader}.
 */
final readonly class DocumentIndexer
{
    public function __construct(
        private DocumentStoreRepository $storeRepository,
        private ProcurementStoreRepository $procurementStore,
    ) {}

    /**
     * @param  list<DocumentData>  $documents
     */
    public function index(Procurement $procurement, array $documents): void
    {
        DB::transaction(function () use ($procurement, $documents): void {
            $activeExternalIds = [];

            foreach ($documents as $document) {
                if ($document->externalId !== null) {
                    $activeExternalIds[] = $document->externalId;
                }

                $this->indexDocument($procurement, $document);
            }

            $this->markRemoved($procurement, $activeExternalIds);
        });
    }

    private function indexDocument(Procurement $procurement, DocumentData $document): void
    {
        $existing = $document->externalId !== null
            ? $this->storeRepository->findByExternalId(procurementId: $procurement->id, externalId: $document->externalId)
            : null;

        if ($existing !== null) {
            $this->storeRepository->update($existing, [
                'title' => $document->title,
                'kind' => $document->kind,
                'source_url' => $document->sourceUrl,
                'removed_at' => null,
            ]);

            return;
        }

        $this->storeRepository->create([
            'procurement_id' => $procurement->id,
            'external_id' => $document->externalId,
            'title' => $document->title,
            'kind' => $document->kind,
            'source_url' => $document->sourceUrl,
            'status' => DocumentStatus::Discovered,
        ]);
    }

    /**
     * @param  list<string>  $activeExternalIds
     */
    private function markRemoved(Procurement $procurement, array $activeExternalIds): void
    {
        $removed = $this->storeRepository->activeExcept(procurementId: $procurement->id, activeExternalIds: $activeExternalIds);

        foreach ($removed as $document) {
            $this->storeRepository->update($document, ['removed_at' => now()]);

            $this->procurementStore->storeEvent(
                procurementId: $procurement->id,
                eventType: ProcurementEventType::DocumentRemoved,
                occurredAt: now(),
                entityRef: ['document_id' => $document->id],
            );
        }
    }
}
