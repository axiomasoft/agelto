<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use Spatie\LaravelData\Data;

/**
 * Коэффициент/индекс позиции (напр. К=1.15, перенос v1 EstimateModifierItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.4). Низкая частота — не отдельная таблица, персистится в
 * prc_estimate_items.attributes->'modifiers' (jsonb).
 * - name: код коэффициента.
 * - description: обоснование (текстовая расшифровка, за что коэффициент).
 * - value: числовое значение коэффициента (decimal-строка — конверсия во float запрещена).
 */
final class RowModifier extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public string $value,
    ) {}
}
