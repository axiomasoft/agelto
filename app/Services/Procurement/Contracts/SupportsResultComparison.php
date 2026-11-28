<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

/**
 * Опциональное сравнение сырого результата теневого прогона с боевым. Ядро проверяет
 * instanceof; отсутствие реализации даёт штатный null. Разбор payload структуры
 * Analysis::$result принадлежит реализации и не переносится в ядро.
 */
interface SupportsResultComparison
{
    /**
     * @param  array<string, mixed>  $primaryResult
     * @param  array<string, mixed>  $shadowResult
     * @return array<string, mixed>|null
     */
    public function compareResults(array $primaryResult, array $shadowResult): ?array;
}
