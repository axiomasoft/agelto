<?php

declare(strict_types=1);

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

            $table->text('step')->comment('screening/extraction');
            $table->text('status')->comment('pending→running→succeeded/failed/skipped');
            $table->text('document_type')->nullable()->comment('code из prc_document_types, без строгого FK');

            $table->jsonb('result')->nullable();
            $table->text('provider')->nullable();
            $table->text('model')->nullable();

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
};
