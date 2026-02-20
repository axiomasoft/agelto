<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema;

use Platform\Process\DocumentAnalysis\Schema\DocumentObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class PrimaryAnalysis extends Data
{
    /**
     * @param  DataCollection<int, DocumentObject>  $objects
     * @param  string[]  $tags
     * @param  string  $description
     * @param  string[]  $tags
     */
    public function __construct(
        public bool $has_estimates,
        #[DataCollectionOf(DocumentObject::class)]
        public DataCollection $objects,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}
