<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class DocumentObject extends Data
{
    public function __construct(
        public ObjectPassport $passport,
        public int $items_count,
        public int $items_count_total,
        public bool $has_footer,
        public ?string $generator = null,
    ) {}
}
