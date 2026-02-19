<?php

declare(strict_types=1);

namespace Platform\Dto\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimateFetchResponseDto extends Data
{
    /**
     * @param  array<int, EstimateSectionDto|EstimateItemDto>  $data
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?string $message = null,
    ) {}
}
