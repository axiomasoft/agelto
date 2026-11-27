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
            "ALTER TABLE {$this->table()} ADD CONSTRAINT prc_ai_analyses_screening_result_schema_check "
            ."CHECK (step <> 'screening' OR status <> 'succeeded' OR superseded_at IS NOT NULL OR is_shadow = true OR ("
            ."result IS NOT NULL AND COALESCE(result->>'schema_version' = '2', false) "
            ."AND COALESCE(jsonb_typeof(result->'screening') = 'object', false) "
            ."AND COALESCE(jsonb_typeof(result->'decision') = 'object', false)))",
        );

        DB::statement(
            "CREATE INDEX prc_ai_analyses_active_screening_type_index ON {$this->table()} (document_type) "
            ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
            ."AND result->>'schema_version' = '2'",
        );
        DB::statement(
            "CREATE INDEX prc_ai_analyses_active_screening_tags_gin_index ON {$this->table()} "
            ."USING GIN ((result #> '{screening,tags}')) "
            ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
            ."AND result->>'schema_version' = '2'",
        );

        foreach ([
            'cardinality' => "result->'screening'->>'estimate_cardinality'",
            'disposition' => "result->'decision'->>'disposition'",
            'reason' => "result->'decision'->>'reason'",
        ] as $name => $expression) {
            DB::statement(
                "CREATE INDEX prc_ai_analyses_active_screening_{$name}_index ON {$this->table()} (({$expression})) "
                ."WHERE step = 'screening' AND superseded_at IS NULL AND is_shadow = false "
                ."AND result->>'schema_version' = '2'",
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'prc_ai_analyses_active_screening_type_index',
            'prc_ai_analyses_active_screening_tags_gin_index',
            'prc_ai_analyses_active_screening_cardinality_index',
            'prc_ai_analyses_active_screening_disposition_index',
            'prc_ai_analyses_active_screening_reason_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement("ALTER TABLE {$this->table()} DROP CONSTRAINT IF EXISTS prc_ai_analyses_screening_result_schema_check");
    }

    private function table(): string
    {
        return (new Analysis)->getTable();
    }
};
