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
 * - unit: единица измерения.
 * - quantity: количество (decimal-строка — конверсия во float запрещена).
 * - price: цена ресурса (decimal-строка).
 */
final class RowResource extends Data
{
    public function __construct(
        public ResourceType $resource_type,
        public string $name,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $price = null,
    ) {}
}
