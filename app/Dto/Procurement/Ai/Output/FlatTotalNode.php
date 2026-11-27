<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Output;

use App\Attributes\Ai\Description;
use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

/** Flat forest, arbitrary semantic depth within the explicit node limit. */
final class FlatTotalNode extends Data
{
    public function __construct(
        #[SchemaConstraints(['minimum' => 1])]
        public int $estimate_ordinal,
        #[SchemaConstraints(['minLength' => 1, 'maxLength' => 128])]
        public string $node_id,
        #[Description('ID родителя в той же смете; null для корня. Не вкладывать дочерние узлы.')]
        public ?string $parent_id,
        #[SchemaConstraints(['minLength' => 1, 'maxLength' => 256])]
        public string $source_anchor,
        public string $label,
        #[SchemaConstraints(['pattern' => MoneyDecimal::PATTERN, 'maxLength' => 80])]
        public ?string $value,
    ) {}
}
