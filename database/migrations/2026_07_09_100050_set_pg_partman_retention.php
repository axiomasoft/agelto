<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Выставляет `partman.part_config.retention`/`retention_keep_table` для эфемерных
 * лог-таблиц (`config('partitioning.retention')`, P7.7, D33) — партиции, зарегистрированные
 * миграцией `2026_07_04_100047_setup_pg_partman.php` БЕЗ retention (research/L finding 4),
 * теперь дропаются `partman.run_maintenance()` (уже вызывается `partitions:maintain`) по
 * истечении окна хранения. `prc_revisions` НЕ упомянута в `config('partitioning.retention')`
 * (D41 — история редакций закупок = доменный аудит, не эфемерный лог) — её
 * `part_config.retention` остаётся `NULL`, партиции не дропаются никогда.
 *
 * Raw-SQL с проверкой «уже выставлен» — тот же приём, что `create_parent` в 100047
 * (Code Guidance P7.7): `migrate:fresh` в dev пересоздаёт схему `public`, но НЕ дропает
 * `partman.part_config` (отдельная схема), поэтому повторный прогон не должен падать
 * на попытке выставить retention дважды — здесь это просто идемпотентный `UPDATE`
 * (в отличие от `create_parent`, повторный `UPDATE ... SET` тех же значений не ошибка,
 * проверка добавлена только чтобы не делать лишний SQL-вызов на каждый `migrate:fresh`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (config('partitioning.retention') as $table => $rule) {
            $this->applyRetention(
                table: $table,
                retention: sprintf('%d months', $rule['months']),
                keepTable: $rule['keep_table'],
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(config('partitioning.retention')) as $table) {
            // retention_keep_table → true — исходный default `create_parent()` (100047),
            // не значение из `retention`-конфига (симметрично отменяет ровно этот up()).
            DB::statement(
                'UPDATE partman.part_config SET retention = NULL, retention_keep_table = true WHERE parent_table = ?',
                ['public.'.$table],
            );
        }
    }

    private function applyRetention(string $table, string $retention, bool $keepTable): void
    {
        $parentTable = 'public.'.$table;

        $current = DB::selectOne(
            'SELECT retention, retention_keep_table AS keep_table FROM partman.part_config WHERE parent_table = ?',
            [$parentTable],
        );

        if ($current !== null && $current->retention === $retention && (bool) $current->keep_table === $keepTable) {
            return;
        }

        DB::statement(
            'UPDATE partman.part_config SET retention = ?, retention_keep_table = ? WHERE parent_table = ?',
            [$retention, $keepTable, $parentTable],
        );
    }
};
