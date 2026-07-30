<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Illuminate\Support\Collection;

/**
 * Итог `LlmSpendReporter::report()` — строки + суммы за окно; форматирование валют и печать
 * таблицы остаются в команде (`root/service-layer-map.md` §3).
 */
final readonly class LlmSpendReport
{
    /**
     * @param  Collection<int, LlmSpendRow>  $rows
     */
    public function __construct(
        public Collection $rows,
        public int $totalCostMicros,
        public int $totalSavingsMicros,
    ) {}
}
