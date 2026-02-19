<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class EstimateItemDto extends Data
{
    /**
     * @param  DataCollection<int, EstimateItemResourceDto>  $children
     * @param  DataCollection<int, ModifierItemDto>  $modifiers
     */
    public function __construct(
        public string $name,
        public ?int $pos = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $price = null,
        #[DataCollectionOf(EstimateItemResourceDto::class)]
        public DataCollection $children = new DataCollection(EstimateItemResourceDto::class, []),
        #[DataCollectionOf(ModifierItemDto::class)]
        public DataCollection $modifiers = new DataCollection(ModifierItemDto::class, []),
    ) {}
}
