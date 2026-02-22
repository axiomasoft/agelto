<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Spatie\LaravelData\Data;
use Platform\Process\EstimateAnalysis\Schema\Responses\EstimateFetchInfo;

final class EstimateFetchInfoResponse extends Data
{
    public function __construct(
        public bool $success,
        public EstimateFetchInfo $data,
        public ?string $message = null,
    ) {}
}
