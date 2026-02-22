<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema\Responses;

use Platform\Process\EstimateAnalysis\Schema\Responses\EstimateFetchInfo;
use Spatie\LaravelData\Data;

/** Ответ: шапка и итоги сметы. success, message, data (EstimateFetchInfo). */
final class EstimateFetchInfoResponse extends Data
{
    public function __construct(
        public bool $success,
        public EstimateFetchInfo $data,
        public ?string $message = null,
    ) {}
}
