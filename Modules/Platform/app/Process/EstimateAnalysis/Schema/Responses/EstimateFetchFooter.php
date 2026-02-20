<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Platform\Process\EstimateAnalysis\Schema\EstimateFooterItem;

final class EstimateFetchFooter extends Data
{
    public function __construct(
        public string $estimate_id,
        #[DataCollectionOf(EstimateFooterItem::class)]
        public DataCollection $data = new DataCollection(EstimateFooterItem::class, []),
    ) {}
}
