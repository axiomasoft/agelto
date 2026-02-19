<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class EstimateSubSection extends Data
{
    /**
     * @param  DataCollection<int, EstimateItem>  $children
     */
    public function __construct(
        public string $name,
        #[DataCollectionOf(EstimateItem::class)]
        public DataCollection $children = new DataCollection(EstimateItem::class, []),
    ) {}
}
