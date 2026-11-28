<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Rules\CsvDialectRule;

/**
 * Контракт чтения табличных данных (CSV/TSV) в нормализованную матрицу заголовков и строк.
 */
interface TabularReader
{
    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function read(string $content, CsvDialectRule $dialect): array;
}
