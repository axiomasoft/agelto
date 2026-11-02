<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;

final readonly class FinalizeRepresentationAction
{
    public function __construct(private RepresentationStoreRepository $representations) {}

    /** @param array<string, mixed> $attributes */
    public function execute(DocumentVersion $version, RepresentationKind $kind, array $attributes): DocumentRepresentation
    {
        return $this->representations->storeOrUpdate(
            version: $version,
            kind: $kind,
            attributes: $attributes,
        );
    }
}
