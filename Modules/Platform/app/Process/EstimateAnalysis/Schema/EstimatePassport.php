<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

final class EstimatePassport extends Data
{
    public function __construct(
        public string $estimate_id,
        public ?EstimateType $document_type,
        public ?string $reference,
        public ?EstimateTarget $target,
        public ?string $address,
    ) {}
}
