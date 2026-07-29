<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE llm_calls (
                id bigint GENERATED ALWAYS AS IDENTITY,
                analysis_id bigint NULL,
                analysis_run_id bigint NULL,
                purpose text NOT NULL,
                provider text NOT NULL,
                model text NOT NULL,
                prompt_version_set jsonb NULL,
                cache_id text NULL,
                request_ref text NULL,
                response_ref text NULL,
                prompt_tokens int NULL,
                completion_tokens int NULL,
                cached_tokens int NULL,
                total_tokens int NULL,
                cost_micros bigint NULL,
                success boolean NOT NULL,
                error text NULL,
                duration_ms int NULL,
                called_at timestamptz NOT NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                PRIMARY KEY (id, called_at)
            ) PARTITION BY RANGE (called_at)
            SQL);

        DB::statement('CREATE INDEX llm_calls_analysis_id_index ON llm_calls (analysis_id)');
        DB::statement('CREATE INDEX llm_calls_analysis_run_id_index ON llm_calls (analysis_run_id)');
        DB::statement('CREATE INDEX llm_calls_purpose_called_at_index ON llm_calls (purpose, called_at)');
        DB::statement(
            'ALTER TABLE llm_calls ADD CONSTRAINT llm_calls_analysis_id_foreign '
            .'FOREIGN KEY (analysis_id) REFERENCES prc_ai_analyses (id)',
        );

        $this->bootstrapPartitions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS llm_calls CASCADE');
    }

    private function bootstrapPartitions(): void
    {
        $start = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->addMonthNoOverflow();
            $partition = 'llm_calls_'.$from->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF llm_calls FOR VALUES FROM ('%s') TO ('%s')",
                $partition,
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ));
        }
    }
};
