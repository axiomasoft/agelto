<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Models\Procurement\Crawler\Run;

final readonly class ListingExecutionContext
{
    public function __construct(
        public Run $run,
        public float $deadlineAt,
        public \Closure $checkpoint,
    ) {}
}
