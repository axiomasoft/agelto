<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Соответствие полей §8.2 буквам колонок листа (P5.2, D117) — `estimates/gold/<CODE>.
 * profile.json` §`estimates[].columns`. Значение — буква колонки (напр. «I») либо `null` (поле
 * этой формой не печатается); `name` — единственное обязательное поле, остальные опциональны
 * (`SheetGoldMapper` переносит значение ИЗ СВОЕЙ ЯЧЕЙКИ дословно, ничего не агрегируя).
 * `FIELDS` — единственный источник истины для допустимых ключей объекта `columns`, используется
 * `FormProfileReader` для отказа на неизвестном ключе (канон `RowAttributes::ALLOWED_KEYS`, P4.5).
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
}
