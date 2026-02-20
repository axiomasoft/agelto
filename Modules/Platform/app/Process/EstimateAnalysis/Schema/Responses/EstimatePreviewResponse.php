<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Platform\Process\EstimateAnalysis\Schema\Responses\EstimatePreview;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\DataCollection;

final class EstimatePreviewResponse extends Data
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        #[DataCollectionOf(EstimatePreview::class)]
        public DataCollection $data = new DataCollection(EstimatePreview::class, []),
    ) {}
}
