<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use Spatie\LaravelData\Data;

/**
 * Описание найденной колонки таблицы позиций (перенос v1 EstimateHeaderColumn).
 * - index: порядковый номер ячейки в строке (с 0).
 * - name: текст в данной ячейке.
 * - full_name: текст с учётом родительской группы ячеек (составные шапки Гранд-Сметы).
 */
final class HeaderColumn extends Data
{
    public function __construct(
        public int $index,
        public string $name,
        public string $full_name,
    ) {}
}
