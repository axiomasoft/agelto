<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Responses;

use Platform\Process\DocumentAnalysis\Schema\PrimaryAnalysis;
use Spatie\LaravelData\Data;

final class PrimaryAnalysisResponse extends Data
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public PrimaryAnalysis $data,
    ) {}
}
