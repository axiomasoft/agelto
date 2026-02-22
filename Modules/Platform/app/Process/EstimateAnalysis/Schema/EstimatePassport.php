<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

/**
 * Паспорт сметы.
 * - estimate_id: идентификатор, type: EstimateType, target: EstimateTarget.
 * - reference: обоснование/ссылка, address: адрес объекта.
 */
final class EstimatePassport extends Data
{
    public function __construct(
        public string $estimate_id,
        public ?EstimateType $type,
        public ?string $reference,
        public ?EstimateTarget $target,
        public ?string $address,
    ) {}
}
