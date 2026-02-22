<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Platform\Process\EstimateAnalysis\Schema\EstimatePassport;
use Platform\Process\EstimateAnalysis\Schema\EstimateHeaderSchema;
use Platform\Process\EstimateAnalysis\Schema\EstimateFooterItem;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class EstimatePreview extends Data
{
    public function __construct(
        public EstimatePassport $passport,
        public int $items_count,
        public int $items_count_total,
        public int $page_start,
        public int $page_end,
    ) {}
}
