<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Collection;

/** Read complete stored representations for retained format strategies and resolvers. */
final readonly class RepresentationStoreRepository
{
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
