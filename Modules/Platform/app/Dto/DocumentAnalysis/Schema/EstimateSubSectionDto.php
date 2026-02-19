<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class EstimateSubSectionDto extends Data
{
    /**
     * @param  DataCollection<int, EstimateItemDto>  $children
     */
    public function __construct(
        public string $name,
        #[DataCollectionOf(EstimateItemDto::class)]
        public DataCollection $children = new DataCollection(EstimateItemDto::class, []),
    ) {}
}
