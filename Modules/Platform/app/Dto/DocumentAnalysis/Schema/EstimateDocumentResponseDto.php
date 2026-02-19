<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateDocumentResponseDto extends Data
{
    public function __construct(
        public bool $success,
        public DocumentDetailObjectDto $data,
        public ?string $message = null,
    ) {}
}
