<?php

declare(strict_types=1);

namespace App\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/** Подраздел сметы. type: "subsection", name, children: EstimateItem[]. */
final class EstimateSubSection extends Data
{
    /**
     * @param  DataCollection<int, EstimateItem>  $children
     */
    public function __construct(
        public string $type = 'subsection',
        public string $name,
        #[DataCollectionOf(EstimateItem::class)]
        public DataCollection $children = new DataCollection(EstimateItem::class, []),
    ) {}
}
