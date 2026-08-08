<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisRunStatus;
use App\Enums\Procurement\Ai\RunMode;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5 F-01/F-02: `initiated_by` индексирован, версия документа и обратный FK анализа сохраняют
 * явный `NO ACTION`. F-05: `llm_calls.analysis_run_id` получает недостающий FK здесь, после
 * появления позднего родителя; индекс уже создан в 100026. Postgres 17 не поддерживает
 * `NOT VALID` для FK на партиционированном родителе, поэтому используется обычный constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable())
                ->onDelete('NO ACTION');

            $table->text('mode')->comment('auto/manual — App\\Enums\\Procurement\\Ai\\RunMode');
            $table->text('status')->comment('pending→running→succeeded/failed — App\\Enums\\Procurement\\Ai\\AnalysisRunStatus');

            // Слепок расхода (D5) — заполняется P4.1 в терминальных точках контура, здесь только
            // схема (D20). Имена — байт-в-байт как в llm_calls (§3.2 root/document-contour.md).
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('cached_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->bigInteger('cost_micros')->nullable();

            $table->foreignId('initiated_by')
                ->nullable()
                ->index()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->timestamps();
        });

        // Второй незавершённый прогон по той же версии — отвергается базой (§3.2, инцидент
        // «нейронка гнала 6 смет по 7 раз, списалось $10»).
        DB::statement(
            'CREATE UNIQUE INDEX prc_ai_analysis_runs_active_version_unique '
            .'ON prc_ai_analysis_runs (document_version_id) WHERE finished_at IS NULL',
        );

        DB::statement(
            'ALTER TABLE prc_ai_analysis_runs '
            .'ADD CONSTRAINT prc_ai_analysis_runs_mode_check '
            ."CHECK (mode IN ({$this->enumValues(RunMode::cases())}))",
        );
        DB::statement(
            'ALTER TABLE prc_ai_analysis_runs '
            .'ADD CONSTRAINT prc_ai_analysis_runs_status_check '
            ."CHECK (status IN ({$this->enumValues(AnalysisRunStatus::cases())}))",
        );

        // `prc_ai_analyses.analysis_run_id` заведена БЕЗ constrained() в 100025 (миграция этой
        // таблицы ещё не существовала в момент её исполнения — прогон вставляется в 2026-07-29,
        // тот же приём, что prc_procurement_parties.procurement_id → 100048, D20 не запрещает
        // связать FK там, где обе стороны уже существуют).
        Schema::table((new Analysis)->getTable(), function (Blueprint $table): void {
            $table->foreign('analysis_run_id')
                ->references('id')
                ->on($this->table())
                ->onDelete('NO ACTION');
        });

        $llmCalls = (new LlmCall)->getTable();
        DB::statement(
            "ALTER TABLE {$llmCalls} ADD CONSTRAINT {$llmCalls}_analysis_run_id_foreign "
            ."FOREIGN KEY (analysis_run_id) REFERENCES {$this->table()}(id) ON DELETE NO ACTION",
        );
    }

    public function down(): void
    {
        $llmCalls = (new LlmCall)->getTable();
        DB::statement("ALTER TABLE {$llmCalls} DROP CONSTRAINT {$llmCalls}_analysis_run_id_foreign");

        Schema::table((new Analysis)->getTable(), function (Blueprint $table): void {
            $table->dropForeign(['analysis_run_id']);
        });

        DB::statement('DROP INDEX IF EXISTS prc_ai_analysis_runs_active_version_unique');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new AnalysisRun)->getTable();
    }

    /**
     * @param  list<RunMode>|list<AnalysisRunStatus>  $cases
     */
    private function enumValues(array $cases): string
    {
        return collect($cases)
            ->map(static fn (RunMode|AnalysisRunStatus $case): string => "'{$case->value}'")
            ->implode(', ');
    }
};
