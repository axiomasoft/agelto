<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use App\Enums\Procurement\ResourceType;
use Spatie\LaravelData\Data;

/**
 * Ресурс позиции сметы (материал/машина/труд/накладные — перенос v1 EstimateItemResource,
 * docs/specs/v2/08-estimate-extraction.md §1.4/§3.2). Маппится 1:1 в prc_estimate_resources
 * (FK item_id).
 * - resource_type: категория ресурса — material/machine/labor/overhead/other (см. словарь §2.2).
 * - code: шифр ресурса, если указан.
 * - name: наименование ресурса.
 * - unit: единица измерения (цитата документа; разбор на базу/множитель — бэкенд, вне контракта).
 * - quantity: количество (decimal-строка — конверсия во float запрещена).
 * - price_base: цена ресурса на базисном уровне (decimal-строка, SR-02, P4.5) — пуста у ресурсной
 *   сметы (`calc_method=resource`), это законное состояние, а не потеря.
 * - price_curr: цена ресурса на текущем уровне (decimal-строка) — переименовано из `price` (D93):
 *   тот же смысл, что было у `price` до P4.5.
 * - index_code / index_value: код и значение индекса пересчёта ресурса (SR-03, P4.5) — второй
 *   множитель арифметики «базис × индекс ≈ текущая», не подпись индекса (она не хранится).
 */
final class RowResource extends Data
{
    public function __construct(
        public ResourceType $resource_type,
        public string $name,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $price_base = null,
        public ?string $price_curr = null,
        public ?string $index_code = null,
        public ?string $index_value = null,
    ) {}
}
