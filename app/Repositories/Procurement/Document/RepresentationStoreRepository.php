<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\StoredDocumentWriter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Единственная точка персистентности `prc_document_representations` — стратегии подготовки
 * пишут представления только через неё.
 */
final readonly class RepresentationStoreRepository
{
    /**
     * Идемпотентность повторной генерации: ключ — пара версия+kind, второй прогон обновляет
     * ту же запись (та же уникальность, что в схеме).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function storeOrUpdate(DocumentVersion $version, RepresentationKind $kind, array $attributes): DocumentRepresentation
    {
        return app(StoredDocumentWriter::class)->representation($version, $kind, $attributes);
    }

    public function find(DocumentVersion $version, RepresentationKind $kind): ?DocumentRepresentation
    {
        return DocumentRepresentation::query()
            ->where('document_version_id', $version->id)
            ->where('kind', $kind)
            ->where('range_key', 'complete')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, DocumentRepresentation>
     */
    public function allFor(DocumentVersion $version): Collection
    {
        return DocumentRepresentation::query()
            ->where('document_version_id', $version->id)
            ->get();
    }
}
