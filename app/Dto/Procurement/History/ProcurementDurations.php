<?php

declare(strict_types=1);

namespace App\Dto\Procurement\History;

use App\Enums\Procurement\ProcurementStatus;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

final class ProcurementDurations extends Data
{
    /** @param array<string, int> $byStatus */
    public function __construct(
        public ?Carbon $discoveredAt,
        public Carbon $publishedAt,
        public ?ProcurementStatus $terminalStatus,
        public ?Carbon $terminalAt,
        public ?int $totalDuration,
        public array $byStatus,
    ) {}
}
