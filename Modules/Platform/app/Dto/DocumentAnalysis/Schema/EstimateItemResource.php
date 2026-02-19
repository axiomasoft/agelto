<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateItemResource extends Data
{
    public function __construct(
        public string $name,
        public ResourceType $resource_type,
        public ?string $code = null,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $price = null,
        public ?string $comment = null,
    ) {}
}
