<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P7.5, находка F-08 из research/08-db-review.md (в Scope по D132): `pipeline:stuck-check`
 * (`PipelineStuckCheck.php:168`, расписание каждые 15 минут) и `PipelineStatus.php:142`
 * фильтруют `prc_ai_analyses.result` предикатами `whereRaw` по ключам jsonb-документа без
 * выражающего индекса — seq scan с распаковкой TOAST на каждом запуске. Таблица пуста
 * сегодня — обычный `CREATE INDEX` без `CONCURRENTLY` допустим (Implementation Rules,
 * п.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = (new Analysis)->getTable();

        DB::statement(
            "CREATE INDEX {$table}_result_branch_reason_index "
            ."ON {$table} ((result->>'branch_reason'))",
        );
        DB::statement(
            "CREATE INDEX {$table}_result_needs_review_index "
            ."ON {$table} (((result->>'needs_review')::boolean))",
        );
    }

    public function down(): void
    {
        $table = (new Analysis)->getTable();

        DB::statement("DROP INDEX IF EXISTS {$table}_result_branch_reason_index");
        DB::statement("DROP INDEX IF EXISTS {$table}_result_needs_review_index");
    }
};
