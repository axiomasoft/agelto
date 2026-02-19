<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class ModifierItem extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public float $value,
    ) {}
}
