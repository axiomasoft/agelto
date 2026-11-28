<?php

declare(strict_types=1);

namespace App\Dto\Llm\Reporting;

use Illuminate\Support\Collection;

/**
 * Итог `LlmSpendReporter::report` — строки + суммы за окно; форматирование валют и печать
 * таблицы остаются в команде. Дневная серия и подпись источника
 * приезжают из `UsageSource` и в отчёте только переносятся: дневной разрез ДОБАВЛЯЕТСЯ к старой
 * таблице, а не заменяет её.
 */
final readonly class LlmSpendReport
{
    /**
     * @param  Collection<int, LlmSpendRow>  $rows
     * @param  list<UsageRow>  $dailyRows
     */
    public function __construct(
        public Collection $rows,
        public int $totalCostMicros,
        public ?int $totalSavingsMicros,
        public array $dailyRows,
        public UsageSignature $signature,
        public bool $beyondHorizon,
        public string $currency = 'USD',
        public int $unknownCalls = 0,
        public int $reservedMicros = 0,
    ) {}
}
