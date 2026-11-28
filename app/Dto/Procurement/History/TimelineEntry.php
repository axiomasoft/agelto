<?php

declare(strict_types=1);

namespace App\Dto\Procurement\History;

use App\Enums\Procurement\ProcurementEventType;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

final class TimelineEntry extends Data
{
    public function __construct(
        public ProcurementEventType $type,
        public Carbon $occurredAt,
        public ?string $field,
        public ?string $oldValue,
        public ?string $newValue,
        public ?int $revisionNumber,
    ) {}
}
