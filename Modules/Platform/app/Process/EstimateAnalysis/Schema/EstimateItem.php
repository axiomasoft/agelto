<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Позиция сметы. Поля соответствуют колонкам EstimateHeader.
 * - name: наименование (обязательно).
 * - children: ресурсы строки (EstimateItemResource).
 * - modifiers: коэффициенты (EstimateModifierItem).
 */
final class EstimateItem extends Data
{
    /**
     * @param  DataCollection<int, EstimateItemResource>  $children
     * @param  DataCollection<int, EstimateModifierItem>  $modifiers
     */
    public function __construct(
        public string $name,
        public ?int $pos = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?float $quantity = null,
        public ?float $price = null,
        #[DataCollectionOf(EstimateItemResource::class)]
        public DataCollection $children = new DataCollection(EstimateItemResource::class, []),
        #[DataCollectionOf(EstimateModifierItem::class)]
        public DataCollection $modifiers = new DataCollection(EstimateModifierItem::class, []),
    ) {}
}
