<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Collection;
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
        $document->update($attributes);
    }

    /**
     * Документы закупки, ранее не помеченные `removed_at`, чей `external_id` больше
     * не встречается на текущем срезе карточки (снят с вкладки `documents`).
     *
     * @param  list<string>  $activeExternalIds
     * @return Collection<int, Document>
     */
    public function activeExcept(int $procurementId, array $activeExternalIds): Collection
    {
        return Document::query()
            ->where('procurement_id', $procurementId)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', $activeExternalIds)
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
            ->where('content_hash', $contentHash)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createVersion(array $attributes): DocumentVersion
    {
        return DocumentVersion::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVersion(DocumentVersion $version, array $attributes): void
    {
        $version->update($attributes);
    }

    /**
     * Последняя (по времени скачивания) версия документа — источник для стадии подготовки.
     */
    public function latestVersion(int $documentId): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->latest('downloaded_at')
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

    /**
     * Точечная запись диска версии по `id` — write-путь backfill'а (P6.5), когда объект под рукой
     * только как примитив (id/disk/path/hash), не гидратированная модель.
     */
    public function updateVersionDisk(int $id, string $disk): void
    {
        DocumentVersion::query()->whereKey($id)->update(['disk' => $disk]);
    }
}
