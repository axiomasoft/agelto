<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class ObjectPassportDto extends Data
{
    public function __construct(
        public string $object_id,
        public ?DocumentType $document_type,
        public ?string $reference,
        public ?DocumentTarget $target,
        public ?string $object_address,
    ) {}
}
