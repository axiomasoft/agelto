<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7.5 F-01/F-02/F-08: FK-индексы обратной связи поглощены в финальную форму; связи с версией
 * документа и прогоном сохраняют `NO ACTION` явно. Выражения по `result.branch_reason` и
 * `result.needs_review` обслуживают `pipeline:stuck-check` и `PipelineStatus` без seq scan с
 * распаковкой TOAST (research/08-db-review.md, D132).
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

            // Canonical run FK is attached after its baseline table exists.
            $table->uuid('analysis_run_id')->nullable()->index();

            $table->text('step')->comment('screening/extraction');
            $table->text('status')->comment('Accepted result metadata; execution authority is prc_process_work.');
            $table->text('document_type')->nullable()->comment('вердикт скрининга; значения — AiDocumentType, целостность — check-constraint (D16/D17)');

            $table->jsonb('result')->nullable();
            $table->text('provider')->nullable();
            $table->text('model')->nullable();

            // Слепок расхода вызова — заполняется P4, здесь только схема (D20).
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('cached_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->bigInteger('cost_micros')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // Обратная связь оператора — P9.1 (D15, аудит F ось 6).
            $table->text('review_status')->default('unreviewed')->comment('unreviewed/approved/rejected');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->index()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('operator_comment')->nullable();

            $table->foreignId('parent_analysis_id')
                ->nullable()
                ->index()
                ->constrained(table: (new Analysis)->getTable())
                ->nullOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->boolean('is_shadow')->default(false)->comment('D24 — теневой прогон, вне активного unique');

            $table->timestamps();
        });

        // Partial unique: один АКТИВНЫЙ боевой анализ на версию+шаг — история перепрогонов
        // (superseded_at) и теневые прогоны (is_shadow) вне ограничения (P9.1, D15/D24).
        DB::statement(
            'CREATE UNIQUE INDEX prc_ai_analyses_active_version_step_unique '
            .'ON prc_ai_analyses (document_version_id, step) '
            .'WHERE superseded_at IS NULL AND is_shadow = false',
        );

        DB::statement(
            "CREATE INDEX {$this->table()}_result_branch_reason_index "
            ."ON {$this->table()} ((result->>'branch_reason'))",
        );
        DB::statement(
            "CREATE INDEX {$this->table()}_result_needs_review_index "
            ."ON {$this->table()} (((result->>'needs_review')::boolean))",
        );

        // Ось типа документа (D16/D17, §1.3 root/document-type-axis.md): единственный носитель
        // списка значений — AiDocumentType::values(), колонка остаётся nullable (§3.1 контракта).
        DB::statement(
            'ALTER TABLE prc_ai_analyses '
            .'ADD CONSTRAINT prc_ai_analyses_document_type_check '
            ."CHECK (document_type IS NULL OR document_type IN ({$this->documentTypeValues()}))",
        );
        DB::unprepared(<<<'SQL'
            ALTER TABLE prc_ai_analyses ADD CONSTRAINT prc_ai_analyses_result_status_check CHECK(status IN ('succeeded','skipped'));
            CREATE OR REPLACE FUNCTION prc_ai_accepted_result_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN
                    RAISE EXCEPTION 'Accepted analysis result is immutable' USING ERRCODE='23514';
                END IF;
                IF (to_jsonb(OLD)-ARRAY['review_status','reviewed_by','reviewed_at','operator_comment','superseded_at','updated_at']) IS DISTINCT FROM
                   (to_jsonb(NEW)-ARRAY['review_status','reviewed_by','reviewed_at','operator_comment','superseded_at','updated_at']) OR
                   (OLD.superseded_at IS NOT NULL AND OLD.superseded_at IS DISTINCT FROM NEW.superseded_at) THEN
                    RAISE EXCEPTION 'Accepted analysis result is immutable' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER prc_ai_accepted_result_immutable BEFORE UPDATE OR DELETE ON prc_ai_analyses
                FOR EACH ROW EXECUTE FUNCTION prc_ai_accepted_result_guard();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_ai_analyses_active_version_step_unique');
        Schema::dropIfExists($this->table());
        DB::statement('DROP FUNCTION IF EXISTS prc_ai_accepted_result_guard()');
    }

    private function table(): string
    {
        return (new Analysis)->getTable();
    }

    private function documentTypeValues(): string
    {
        return collect(AiDocumentType::values())
            ->map(static fn (string $value): string => "'{$value}'")
            ->implode(', ');
    }
};
