<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\StoredDocumentWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Единственная точка персистентности `prc_documents`/`prc_document_versions` для `DocumentIndexer`
 * и `DocumentDownloader` — сервисы решают, когда и с какими атрибутами писать, репозиторий
 * только читает/пишет по уже принятым ключам (аналог `OrganizationStoreRepository`).
 */
final readonly class DocumentStoreRepository
{
    /**
     * `$procurementId` nullable — дочерние документы архива и manual-импорт
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
        $document->update($attributes);
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
     * Версии по возрастанию `id`, начиная с `$fromId` — read-путь backfill'а дисков (
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
