<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Responses;

use Platform\Process\EstimateAnalysis\Schema\EstimateFooterItem;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Data;

final class EstimateFetchResponse extends Data
{
    public function __construct(
        public bool $success,
        #[DataCollectionOf(EstimateFooterItem::class)]
        public DataCollection $data = new DataCollection(EstimateFooterItem::class, []),
        public ?string $message = null,
    ) {}
}
