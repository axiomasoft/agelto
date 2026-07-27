<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Ресурс позиции сметы из `Data.xml` (P3.3, `<Resources>`).
 * - resource_type: имя XML-тега ресурса как есть (`Mat`|`Mch`|`Work`|`Tzr`|`Tzm`, разведка §3) —
 *   не PHP backed-enum: реальный корпус может нести не все пять на конкретной позиции, а маппинг
 *   в схему смет `prc_estimate_resources.resource_type` — решение P4/P5, не этого item'а.
 * - name: наименование ресурса (`Caption`).
 * - code: шифр ресурса (`Code`), если есть.
 * - unit: единица измерения (`Units`).
 * - quantity: количество, decimal-строка как в XML (запятая — разделитель, конверсия запрещена).
 * - price_base: базовая цена (`PriceBase/@Value`), decimal-строка.
 * - price_curr: текущая цена (`PriceCurr/@Value`), decimal-строка.
 * - index_code: код индекса пересчёта (`IndexCode`), ссылка на глобальный каталог `<Indexes>`
 *   документа (в модель самого каталога не переносится — см. `GsfxDocument::$unmapped`).
 */
final class GsfxResource extends Data
{
    public function __construct(
        public string $resource_type,
        public string $name,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $price_base = null,
        public ?string $price_curr = null,
        public ?string $index_code = null,
    ) {}
}
