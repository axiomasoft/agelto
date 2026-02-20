<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class PrimaryAnalysis extends Data
{
    /**
     * @param  DocumentType $document_type
     * @param  ?string  $description
     * @param  string[]  $tags
     */
    public function __construct(
        public DocumentType $document_type,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}
