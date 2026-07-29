<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('document_version_id')
                ->index()
                ->constrained(table: (new DocumentVersion)->getTable());

            // Без constrained() — prc_ai_analysis_runs заводится позже (2026_07_29_100059,
            // D91/D93 root/document-contour.md §3.3), FK связывается там же (тот же приём, что
            // prc_procurement_parties.procurement_id → 2026_07_04_100048).
            $table->unsignedBigInteger('analysis_run_id')->index();

            $table->text('step')->comment('screening/extraction');
            $table->text('status')->comment('pending→running→succeeded/failed/skipped');
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

            $table->text('locked_by')->nullable();
            $table->timestampTz('locked_until')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // Кэш и прогресс — перенос из v1 plt_document_analysis.
            $table->text('cache_id')->nullable()->comment('Gemini cachedContent — быстрая проверка валидности без похода в API');
            $table->timestampTz('cache_expires_at')->nullable();
            $table->text('rules_hash')->nullable()->comment('инвалидация кэша при смене версии промптов');
            $table->jsonb('progress')->nullable()->comment('резюме прерванной многошаговой экстракции');

            // Обратная связь оператора — P9.1 (D15, аудит F ось 6).
            $table->text('review_status')->default('unreviewed')->comment('unreviewed/approved/rejected');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained(table: (new User)->getTable())
                ->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('operator_comment')->nullable();

            $table->foreignId('parent_analysis_id')
                ->nullable()
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

        // Ось типа документа (D16/D17, §1.3 root/document-type-axis.md): единственный носитель
        // списка значений — AiDocumentType::values(), колонка остаётся nullable (§3.1 контракта).
        DB::statement(
            'ALTER TABLE prc_ai_analyses '
            .'ADD CONSTRAINT prc_ai_analyses_document_type_check '
            ."CHECK (document_type IS NULL OR document_type IN ({$this->documentTypeValues()}))",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_ai_analyses_active_version_step_unique');
        Schema::dropIfExists($this->table());
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
