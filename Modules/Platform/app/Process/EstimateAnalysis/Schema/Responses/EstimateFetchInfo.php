<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Platform\Process\EstimateAnalysis\Schema\EstimateFooterItem;
use Platform\Process\EstimateAnalysis\Schema\EstimateHeader;

final class EstimateFetchInfo extends Data
{
    public function __construct(
        public string $estimate_id,
        public ?string $generator = null,
        public EstimateHeader $header,
        #[DataCollectionOf(EstimateFooterItem::class)]
        public DataCollection $footer = new DataCollection(EstimateFooterItem::class, []),
    ) {}
}
