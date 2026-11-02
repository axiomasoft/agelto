<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

final class PageDiscoveryOutput extends Data
{
    /**
     * @param  DiscoveredPage[]  $pages
     * @param  int[]  $ambiguous_pages
     */
    public function __construct(
        #[SchemaConstraints(['minItems' => 1, 'maxItems' => 4096])]
        public array $pages,
        #[SchemaConstraints(['maxItems' => 4096])]
        public array $ambiguous_pages,
    ) {}
}
