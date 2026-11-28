<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_stat_statements требует `shared_preload_libraries`, заданный в конфигурации
 * PostgreSQL; перезапуск кластера выполняется вне миграции. Для pgstattuple
 * достаточно установки расширения без перезапуска.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgstattuple');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pgstattuple');
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
};
