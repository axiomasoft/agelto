<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateDocumentResponse extends Data
{
    public function __construct(
        public bool $success,
        public DocumentDetailObject $data,
        public ?string $message = null,
    ) {}
}
