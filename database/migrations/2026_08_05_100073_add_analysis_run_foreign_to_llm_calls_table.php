<?php

declare(strict_types=1);

use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\AnalysisRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P7.5, находка F-05 из research/08-db-review.md: `llm_calls.analysis_run_id` не несла
 * FK на `prc_ai_analysis_runs`, хотя `analysis_id` из той же таблицы FK ИМЕЕТ — пропуск,
 * не запрет. Учёт стоимости прогона (`ai:cost-report`) может ссылаться на удалённый
 * прогон без этого констрейнта. Индекс уже существует
 * (`llm_calls_analysis_run_id_index`) — только констрейнт. `llm_calls` партиционирована
 * (0 строк сегодня) — `ADD CONSTRAINT` на партиционированном родителе распространяется на
 * все партиции автоматически; Postgres 17 не поддерживает `NOT VALID` при добавлении FK
 * на партиционированный родитель (`SQLSTATE[42809]`, проверено на дереве) — обычный
 * `ADD CONSTRAINT` безопасен: таблица пуста.
 */
return new class extends Migration
{
    public function up(): void
    {
        $llmCalls = (new LlmCall)->getTable();
        $analysisRuns = (new AnalysisRun)->getTable();

        DB::statement(
            "ALTER TABLE {$llmCalls} ADD CONSTRAINT {$llmCalls}_analysis_run_id_foreign "
            ."FOREIGN KEY (analysis_run_id) REFERENCES {$analysisRuns}(id) ON DELETE NO ACTION",
        );
    }

    public function down(): void
    {
        $llmCalls = (new LlmCall)->getTable();

        DB::statement("ALTER TABLE {$llmCalls} DROP CONSTRAINT {$llmCalls}_analysis_run_id_foreign");
    }
};
