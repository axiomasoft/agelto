<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\ResourceType;
use Spatie\LaravelData\Data;

/**
 * Ресурс позиции сметы (материал/машина/труд/накладные — перенос v1 EstimateItemResource,
 * docs/specs/v2/08-estimate-extraction.md §1.4/§3.2). Маппится 1:1 в prc_estimate_resources
 * (FK item_id).
 */
final class RowResource extends Data
{
    public function __construct(
        #[Description('категория ресурса — material/machine/labor/overhead/other.')]
        public ResourceType $resource_type,
        #[Description('наименование ресурса.')]
        public string $name,
        #[Description('шифр ресурса, если указан.')]
        public ?string $code = null,
        #[Description('единица измерения (цитата документа; разбор на базу/множитель — бэкенд, вне контракта).')]
        public ?string $unit = null,
        #[Description('количество (decimal-строка — конверсия во float запрещена).')]
        public ?string $quantity = null,
        #[Description('цена ресурса на базисном уровне (decimal-строка) — пуста у ресурсной сметы (calc_method=resource), это законное состояние, а не потеря.')]
        public ?string $price_base = null,
        #[Description('цена ресурса на текущем уровне (decimal-строка).')]
        public ?string $price_curr = null,
        #[Description('код индекса пересчёта ресурса — второй множитель арифметики «базис × индекс ≈ текущая», не подпись индекса (она не хранится).')]
        public ?string $index_code = null,
        #[Description('значение индекса пересчёта ресурса — второй множитель арифметики «базис × индекс ≈ текущая», не подпись индекса (она не хранится).')]
        public ?string $index_value = null,
    ) {}
}
