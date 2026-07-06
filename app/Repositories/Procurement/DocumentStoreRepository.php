<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Единственная точка персистентности `prc_documents`/`prc_document_versions` для `DocumentIndexer`
 * и `DocumentDownloader` — сервисы решают, когда и с какими атрибутами писать, репозиторий
 * только читает/пишет по уже принятым ключам (аналог `OrganizationStoreRepository`, P3.4).
 */
final readonly class DocumentStoreRepository
{
    public function findByExternalId(int $procurementId, string $externalId): ?Document
    {
        return Document::query()
            ->where('procurement_id', $procurementId)
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
}
