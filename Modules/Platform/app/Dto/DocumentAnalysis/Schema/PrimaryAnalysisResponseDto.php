<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class PrimaryAnalysisResponseDto extends Data
{
    /**
     * @param  DataCollection<int, DocumentObjectDto>  $objects
     * @param  string[]  $tags
     */
    public function __construct(
        public bool $success,
        public bool $has_estimates,
        #[DataCollectionOf(DocumentObjectDto::class)]
        public DataCollection $objects,
        public ?string $message = null,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}
