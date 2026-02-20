<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema\Responses;

use Platform\Process\DocumentAnalysis\Schema\DocumentObject;
use Platform\Process\DocumentAnalysis\Schema\PrimaryAnalysis;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class PrimaryAnalysisResponse extends Data
{
    /**
     * @param  DataCollection<int, DocumentObject>  $data
     * @param  string[]  $tags
     */
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public PrimaryAnalysis $data,
    ) {}
}
