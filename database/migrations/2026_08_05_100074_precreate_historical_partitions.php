<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P7.5, находка F-06 из research/08-db-review.md (Scope Included по D132/D133): партиции
 * пяти таблиц покрывали РОВНО `[2026-08-01, 2026-11-01)`, DEFAULT-партиции нет ни у одной
 * — строка с доменной меткой времени вне окна отвергается (`no partition of relation …
 * found for row`), уже наблюдалось P4.16. Решение владельца (D133, гейт P7.1 §12,
 * F-06-гейт): закрывать выход за диапазон ПРЕДСОЗДАНИЕМ исторических партиций на глубину
 * 12 месяцев назад от начала текущего окна — `[2025-08-01, 2026-08-01)`, не
 * DEFAULT-партицией (сохраняет partition pruning). Партиции создаются прямым DDL
 * (`CREATE TABLE … PARTITION OF`), а не `partman.create_partition_time()`: у неё
 * собственная схема имён (`_pYYYYMMDD`), расходящаяся с именами, которые уже даёт
 * штатный premake `create_parent()` (`_YYYY_MM`, `2026_07_04_100047_setup_pg_partman.php`)
 * — расхождение проверено спайком на дереве (создано и удалено до применения этой
 * миграции), два формата имён партиций одной таблицы читались бы как два разных
 * механизма. Индексы/CHECK партиционированного родителя наследуются автоматически
 * (declarative partitioning, PG12+); `template_table` каждой из пяти таблиц не несёт
 * ничего сверх колонок (проверено — `\d partman.template_public_*` пуст от доп. атрибутов).
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
