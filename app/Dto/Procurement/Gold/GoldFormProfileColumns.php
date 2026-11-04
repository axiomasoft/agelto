<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Соответствие полей gold буквам колонок в `estimates/gold/<CODE>.profile.json`,
 * объект `estimates[].columns`. Значение — буква колонки либо `null`, если поле не печатается.
 * `name` обязателен; остальные поля опциональны. `SheetGoldMapper` переносит значения
 * из указанных ячеек дословно, без агрегации. `FIELDS` задаёт допустимые ключи;
 * `FormProfileReader` отклоняет неизвестные, согласуя их с `RowAttributes::ALLOWED_KEYS`.
 */
final class GoldFormProfileColumns extends Data
{
    /**
     * @var list<string>
     */
    public const array FIELDS = [
        'source_position_number',
        'code',
        'name',
        'unit',
        'quantity',
        'unit_price_base',
        'index_value',
        'unit_price_curr',
        'total_price_base',
        'total_price_curr',
        'amount_since_start',
        'amount_since_year_start',
        'amount_reporting_period',
    ];

    public function __construct(
        public string $name,
        public ?string $source_position_number = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $unit_price_base = null,
        public ?string $index_value = null,
        public ?string $unit_price_curr = null,
        public ?string $total_price_base = null,
        public ?string $total_price_curr = null,
        public ?string $amount_since_start = null,
        public ?string $amount_since_year_start = null,
        public ?string $amount_reporting_period = null,
    ) {}

    public function letterFor(string $field): ?string
    {
        return match ($field) {
            'source_position_number' => $this->source_position_number,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_price_base' => $this->unit_price_base,
            'index_value' => $this->index_value,
            'unit_price_curr' => $this->unit_price_curr,
            'total_price_base' => $this->total_price_base,
            'total_price_curr' => $this->total_price_curr,
            'amount_since_start' => $this->amount_since_start,
            'amount_since_year_start' => $this->amount_since_year_start,
            'amount_reporting_period' => $this->amount_reporting_period,
            default => null,
        };
    }
}
