<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт исторические месячные партиции `[2025-08-01, 2026-08-01)` для таблиц
 * из `partitioning.tables`. Без DEFAULT-партиции запись за пределами покрытого диапазона
 * отклоняется PostgreSQL; явные месячные границы сохраняют partition pruning.
 *
 * Прямой `CREATE TABLE ... PARTITION OF` сохраняет формат имён `_YYYY_MM`,
 * согласованный с исходным pg_partman setup. Индексы и CHECK партиционированного
 * родителя наследуются автоматически.
 */
return new class extends Migration
{
    private const int MONTHS_BACK = 12;

    public function up(): void
    {
        foreach (array_keys(config('partitioning.tables')) as $table) {
            foreach ($this->months() as $month) {
                $this->createPartition($table, $month);
            }
        }
    }

    public function down(): void
    {
        $suffixes = array_map(static fn (Carbon $month): string => $month->format('Y_m'), $this->months());

        foreach (array_keys(config('partitioning.tables')) as $table) {
            foreach ($suffixes as $suffix) {
                DB::statement("DROP TABLE IF EXISTS {$table}_{$suffix}");
            }
        }
    }

    private function createPartition(string $table, Carbon $month): void
    {
        $suffix = $month->format('Y_m');
        $from = $month->toDateString();
        $to = $month->clone()->addMonthNoOverflow()->toDateString();

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$table}_{$suffix} PARTITION OF {$table} "
            ."FOR VALUES FROM ('{$from}') TO ('{$to}')",
        );
    }

    /** @return list<Carbon> помесячные границы `[2025-08-01, 2026-08-01)` — 12 элементов */
    private function months(): array
    {
        $windowStart = Carbon::parse('2026-08-01', 'UTC');

        return array_map(
            static fn (int $i): Carbon => $windowStart->clone()->subMonthsNoOverflow(self::MONTHS_BACK - $i),
            range(0, self::MONTHS_BACK - 1),
        );
    }
};
