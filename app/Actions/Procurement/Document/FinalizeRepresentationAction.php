<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Document\StoredDocumentWriter;

final readonly class FinalizeRepresentationAction
{
    public function __construct(private StoredDocumentWriter $writer) {}

    /** @param array<string, mixed> $attributes */
    public function execute(DocumentVersion $version, RepresentationKind $kind, array $attributes): DocumentRepresentation
    {
        return $this->writer->representation(
            version: $version,
            kind: $kind,
            attributes: $attributes,
        );
    }
}
