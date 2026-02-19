<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class EstimateItem extends Data
{
    /**
     * @param  DataCollection<int, EstimateItemResource>  $children
     * @param  DataCollection<int, ModifierItem>  $modifiers
     */
    public function __construct(
        public string $name,
        public ?int $pos = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $price = null,
        #[DataCollectionOf(EstimateItemResource::class)]
        public DataCollection $children = new DataCollection(EstimateItemResource::class, []),
        #[DataCollectionOf(ModifierItem::class)]
        public DataCollection $modifiers = new DataCollection(ModifierItem::class, []),
    ) {}
}
