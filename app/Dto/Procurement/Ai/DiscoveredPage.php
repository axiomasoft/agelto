<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

final class DiscoveredPage extends Data
{
    public function __construct(
        #[SchemaConstraints(['minimum' => 0])]
        public int $page_index,
        #[SchemaConstraints(['minimum' => 1, 'maximum' => 128])]
        public int $estimate_ordinal,
    ) {}
}
