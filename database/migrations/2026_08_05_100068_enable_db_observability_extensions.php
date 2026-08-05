<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P7.4 (F-12) — pg_stat_statements требует `shared_preload_libraries` (задан в
 * docker-compose*.yml `command:`, рестарт кластера — вне этой миграции); pgstattuple —
 * обычное расширение, рестарт не нужен.
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
