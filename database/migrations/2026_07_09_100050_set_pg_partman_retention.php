<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Выставляет `partman.part_config.retention`/`retention_keep_table` для эфемерных
 * лог-таблиц из `partitioning.retention`. `partitions:maintain` вызывает
 * `partman.run_maintenance()`, которая удаляет партиции за пределами окна хранения.
 * `prc_revisions` сохраняет доменный аудит: её retention остаётся NULL.
 *
 * Идемпотентный UPDATE нужен и для повторной установки: пересоздание схемы public
 * не удаляет отдельную схему partman и её конфигурацию.
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
