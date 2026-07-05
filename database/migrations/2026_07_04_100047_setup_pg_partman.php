<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Регистрирует 5 уже существующих партиционированных таблиц (`prc_events`,
 * `prc_revisions`, `ai_llm_calls`, `crw_fetches`, `sys_operation_logs`) в pg_partman
 * для автоматического premake партиций на 3 месяца вперёд (см. `01-database.md` §0.4).
 *
 * `p_start_partition` намеренно указан НАЧИНАЯ СО СЛЕДУЮЩЕЙ партиции после уже
 * созданных вручную bootstrap-партиций (см. миграции P1.2/P1.6/P1.8 и эту, +3 месяца
 * от начала текущего) — иначе `create_parent` попытается создать партиции с
 * перекрывающимся диапазоном под другим именем и Postgres отклонит их как overlap.
 *
 * `php artisan migrate:fresh` дропает таблицы только в текущей search_path-схеме
 * (`public`) — расширение `pg_partman` и его `partman.part_config` в отдельной схеме
 * НЕ дропаются и переживают `migrate:fresh` как есть. Поэтому регистрация каждой
 * таблицы в `create_parent()` — с явной проверкой «уже зарегистрирована», иначе
 * повторный `migrate:fresh` падает на дублирующемся PK `part_config.parent_table`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pg_partman без явного SCHEMA ставится в текущую default-схему (public), а не
        // в "partman" — все функции/part_config документированы под partman.*, поэтому
        // схема создаётся и указывается явно.
        DB::statement('CREATE SCHEMA IF NOT EXISTS partman');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_partman SCHEMA partman');

        $start = Carbon::now()->startOfMonth()->addMonthsNoOverflow(3);

        foreach (config('partitioning.tables') as $table => $column) {
            $parentTable = 'public.'.$table;

            $alreadyRegistered = DB::selectOne(
                'SELECT 1 AS registered FROM partman.part_config WHERE parent_table = ?',
                [$parentTable],
            );

            if ($alreadyRegistered) {
                continue;
            }

            DB::selectOne(
                'SELECT partman.create_parent('
                .'p_parent_table := ?, p_control := ?, p_type := ?, p_interval := ?, '
                .'p_premake := ?, p_start_partition := ?)',
                [
                    $parentTable,
                    $column,
                    'range',
                    config('partitioning.interval'),
                    config('partitioning.premake'),
                    $start->toDateTimeString(),
                ],
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(config('partitioning.tables')) as $table) {
            DB::table('partman.part_config')->where('parent_table', 'public.'.$table)->delete();
        }

        DB::statement('DROP EXTENSION IF EXISTS pg_partman');
        DB::statement('DROP SCHEMA IF EXISTS partman CASCADE');
    }
};
