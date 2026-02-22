<?php

declare(strict_types=1);

namespace Platform\Process\EstimateAnalysis\Schema;

use Spatie\LaravelData\Data;

/**
 * Описание колонки таблицы.
 * - index: порядковый номер ячейки в строке (с 0).
 * - name: текст в данной ячейке.
 * - full_name: текст с учётом родительской группы ячеек.
 */
final class EstimateHeaderColumn extends Data
{
    public function __construct(
        public int $index,
        public string $name,
        public string $full_name,
    ) {}
}
