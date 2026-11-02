<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\StoredDocumentWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Единственная точка персистентности `prc_documents`/`prc_document_versions` для `DocumentIndexer`
 * и `DocumentDownloader` — сервисы решают, когда и с какими атрибутами писать, репозиторий
 * только читает/пишет по уже принятым ключам (аналог `OrganizationStoreRepository`, P3.4).
 */
final readonly class DocumentStoreRepository
{
    /**
     * `$procurementId` nullable — дочерние документы архива (P4.2) и manual-импорт (P7.4, D22)
     * могут существовать вне закупки.
     */
    public function findOrFail(int $id): Document
    {
        return Document::query()->findOrFail($id);
    }

    public function findByExternalId(?int $procurementId, string $externalId): ?Document
    {
        return Document::query()
            ->where(fn ($query) => $procurementId !== null
                ? $query->where('procurement_id', $procurementId)
                : $query->whereNull('procurement_id'))
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Document
    {
        return Document::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Document $document, array $attributes): void
    {
        $leaseToken = $document->leaseToken();

        if ($leaseToken !== null) {
            $document->fill($attributes);
            $updated = Document::query()
                ->whereKey($document->getKey())
                ->where('locked_by', $leaseToken)
                ->whereRaw('locked_until >= CURRENT_TIMESTAMP')
                ->update($document->getDirty());

            if ($updated !== 1) {
                throw new \RuntimeException('Document lease was lost before persistence.');
            }

            $document->refresh();

            return;
        }

        $document->update($attributes);
    }

    public function acquireRevalidationLease(Document $document, string $owner, int $ttlSeconds): bool
    {
        return DB::transaction(function () use ($document, $owner, $ttlSeconds): bool {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);

            if ($lockedDocument->removed_at !== null || $lockedDocument->failed_permanently
                || $lockedDocument->next_retry_at?->isFuture()) {
                return false;
            }

            $version = $this->latestVersion(documentId: $document->id);

            if ($version === null || AnalysisRun::query()
                ->where(column: 'document_version_id', operator: $version->id)
                ->whereNull('finished_at')->exists()) {
                return false;
            }

            if (Analysis::query()
                ->whereIn(column: 'document_version_id', values: DocumentVersion::query()
                    ->select('id')->where(column: 'document_id', operator: $document->id))
                ->where(column: 'locked_until', operator: '>=', value: now())->exists()) {
                return false;
            }

            if (! $document->acquireLease(owner: $owner, ttlSeconds: $ttlSeconds)) {
                return false;
            }

            $document->setRawAttributes(attributes: $lockedDocument->getAttributes(), sync: true);

            return true;
        });
    }

    /**
     * Исходные platform attachments закупки, ранее не помеченные `removed_at`, чей
     * `external_id` больше не встречается в complete snapshot вкладки `documents`.
     * Archive children исключаются через явный `parent_version_id`, а не по виду external ID.
     *
     * @param  list<string>  $activeExternalIds
     * @return Collection<int, Document>
     */
    public function activePlatformAttachmentsExcept(int $procurementId, array $activeExternalIds): Collection
    {
        return Document::query()
            ->where('procurement_id', $procurementId)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', $activeExternalIds)
            ->whereNotIn('id', DocumentVersion::query()
                ->select('document_id')
                ->whereNotNull('parent_version_id'))
            ->whereNull('removed_at')
            ->get();
    }

    public function countVersions(int $documentId): int
    {
        return DocumentVersion::query()->where('document_id', $documentId)->count();
    }

    public function findVersionByHash(int $documentId, string $contentHash): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->whereRaw("content_hash=decode(?,'hex')", [$contentHash])
            ->whereIn('id', Document::query()->select('current_version_id')->whereKey($documentId))
            ->first();
    }

    public function findArchiveEntryVersion(int $parentVersionId, string $archiveEntryPath): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('parent_version_id', $parentVersionId)
            ->where('archive_entry_path', $archiveEntryPath)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createVersion(array $attributes): DocumentVersion
    {
        return app(StoredDocumentWriter::class)->version($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVersion(DocumentVersion $version, array $attributes): void
    {
        throw new \LogicException('Document version metadata is immutable.');
    }

    /**
     * Последняя (по времени скачивания) версия документа — источник для стадии подготовки.
     */
    public function latestVersion(int $documentId): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->whereIn('id', Document::query()->select('current_version_id')->whereKey($documentId))
            ->first();
    }

    /**
     * Версии по возрастанию `id`, начиная с `$fromId` — read-путь backfill'а дисков (P6.5,
     * `research/07-storage-addressing.md` §6): Eloquent cursor, память не растёт с объёмом.
     *
     * @return LazyCollection<int, DocumentVersion>
     */
    public function versionsFromId(int $fromId): LazyCollection
    {
        return DocumentVersion::query()
            ->where('id', '>=', $fromId)
            ->orderBy('id')
            ->select(['id', 'disk', 'storage_path', 'content_hash'])
            ->cursor();
    }
}
