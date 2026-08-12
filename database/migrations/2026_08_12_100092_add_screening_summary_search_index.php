<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Analysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX prc_ai_analyses_active_screening_summary_search_index ON {$this->table()} "
            ."USING GIN (to_tsvector('russian', coalesce(result #>> '{screening,summary}', ''))) "
            ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
            ."AND result->>'schema_version' = '2'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_ai_analyses_active_screening_summary_search_index');
    }

    private function table(): string
    {
        return (new Analysis)->getTable();
    }
};
