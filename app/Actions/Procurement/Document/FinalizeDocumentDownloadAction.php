<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Facades\DB;

/** Finalizes DB facts only after the immutable source object was verified. */
final readonly class FinalizeDocumentDownloadAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private ProcurementStoreRepository $procurements,
        private AnalysisStoreRepository $analyses,
    ) {}

    /** @param array<string, mixed> $versionAttributes */
    public function execute(Document $document, array $versionAttributes): DocumentVersion
    {
        $contentHash = $versionAttributes['content_hash'] ?? null;

        if (! is_string($contentHash)) {
            throw new \InvalidArgumentException('Document version content_hash must be a string.');
        }

        return DB::transaction(function () use ($document, $versionAttributes, $contentHash): DocumentVersion {
            if ($document->hasLeaseToken() && ! $document->ownsLease()) {
                throw new \RuntimeException('Document lease was lost before download finalization.');
            }

            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $previousVersion = $this->documents->latestVersion(documentId: $document->id);
            $version = $this->documents->findVersionByHash(
                documentId: $document->id,
                contentHash: $contentHash,
            );

            $reactivated = $version !== null && $previousVersion?->id !== $version->id;
            $observedAt = now();

            if ($previousVersion?->downloaded_at->greaterThan($observedAt)) {
                $observedAt = $previousVersion->downloaded_at->copy();
            }

            // downloaded_at is stored at second precision and also orders the current version.
            // Keep source changes ordered even when several downloads share one clock tick.
            if ($previousVersion !== null && $previousVersion->id !== $version?->id
                && $previousVersion->downloaded_at->greaterThanOrEqualTo($observedAt->copy()->startOfSecond())) {
                $observedAt = $previousVersion->downloaded_at->copy()->addSecond();
            }

            if ($version === null) {
                $isFirstVersion = $previousVersion === null;
                $version = $this->documents->createVersion(attributes: [...$versionAttributes, 'downloaded_at' => $observedAt]);
            } else {
                $isFirstVersion = false;
                $this->documents->updateVersion(version: $version, attributes: [
                    'downloaded_at' => $observedAt,
                    'disk' => $versionAttributes['disk'],
                    'storage_path' => $versionAttributes['storage_path'],
                ]);

                if (! $reactivated && $lockedDocument->status !== DocumentStatus::Discovered) {
                    $this->documents->update(document: $lockedDocument, attributes: [
                        'attempts' => 0,
                        'last_error' => null,
                        'last_error_at' => null,
                        'next_retry_at' => null,
                        'failed_permanently' => false,
                    ]);
                    $document->setRawAttributes(attributes: $lockedDocument->getAttributes(), sync: true);

                    return $version;
                }

                if ($reactivated) {
                    $this->analyses->supersedeVersion(documentVersionId: $version->id);
                }
            }

            $this->documents->update(document: $lockedDocument, attributes: [
                'status' => DocumentStatus::Downloaded,
                'attempts' => 0,
                'last_error' => null,
                'last_error_at' => null,
                'next_retry_at' => null,
                'failed_permanently' => false,
                'unprocessable_reason' => null,
            ]);
            $document->setRawAttributes(attributes: $lockedDocument->getAttributes(), sync: true);

            if ($document->procurement_id !== null && ($reactivated || ! $this->procurements->hasDocumentVersionEvent($document->procurement_id, $version->id))) {
                $this->procurements->storeEvent(
                    procurementId: $document->procurement_id,
                    eventType: $isFirstVersion ? ProcurementEventType::DocumentAdded : ProcurementEventType::DocumentReplaced,
                    occurredAt: now(),
                    entityRef: ['document_id' => $document->id, 'document_version_id' => $version->id],
                    payload: $reactivated ? ['previous_document_version_id' => $previousVersion?->id] : null,
                );
            }

            return $version;
        });
    }
}
