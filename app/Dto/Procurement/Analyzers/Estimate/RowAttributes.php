<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Dto\Procurement\Ai\MoneyDecimal;
use App\Services\Llm\Schema\SchemaConstraints;
use Spatie\LaravelData\Data;

/**
 * Фиксированная схема `prc_estimate_items.attributes` (jsonb). Ключ, не перечисленный ниже, в
 * `attributes` не пишется вовсе — `EstimateRow::validateAndCreate` отклоняет неизвестный ключ
 * явно («фиксированная схема выражается ОТДЕЛЬНЫМ Data-классом, а не
 * свободным array»).
 */
final class RowAttributes extends Data
{
    /**
     * Единственный источник истины для допустимых ключей `attributes` — используется
     * `EstimateRow::validateAndCreate` для отказа на неизвестном ключе (иначе фиксированная
     * схема существовала бы только на бумаге).
     *
     * @var list<string>
     */
    public const array ALLOWED_KEYS = [
        'modifiers',
        'amount_since_start',
        'amount_since_year_start',
        'amount_reporting_period',
    ];

    /**
     * @param  RowModifier[]  $modifiers
     */
    public function __construct(
        #[Description('коэффициенты позиции.')]
        public array $modifiers = [],
        #[Description('КС-3 — стоимость с начала проведения работ (decimal-строка), заполняется только строкой row_type=summary_row.')]
        #[SchemaConstraints(['pattern' => MoneyDecimal::PATTERN, 'maxLength' => 80])]
        public ?string $amount_since_start = null,
        #[Description('КС-3 — стоимость с начала года (decimal-строка), заполняется только строкой row_type=summary_row.')]
        #[SchemaConstraints(['pattern' => MoneyDecimal::PATTERN, 'maxLength' => 80])]
        public ?string $amount_since_year_start = null,
        #[Description('КС-3 — стоимость за отчётный период (decimal-строка), заполняется только строкой row_type=summary_row.')]
        #[SchemaConstraints(['pattern' => MoneyDecimal::PATTERN, 'maxLength' => 80])]
        public ?string $amount_reporting_period = null,
    ) {}
}
