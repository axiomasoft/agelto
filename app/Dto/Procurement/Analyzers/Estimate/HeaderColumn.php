<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Описание найденной колонки таблицы позиций (перенос v1 EstimateHeaderColumn).
 */
final class HeaderColumn extends Data
{
    public function __construct(
        #[Description('порядковый номер ячейки в строке (с 0).')]
        public int $index,
        #[Description('текст в данной ячейке.')]
        public string $name,
        #[Description('текст с учётом родительской группы ячеек (составные шапки Гранд-Сметы).')]
        public string $full_name,
    ) {}
}
