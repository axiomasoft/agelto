<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->index(['model_type', 'model_id'], 'plt_doc_analysis_model_idx');
            $table->foreignId('parent_id')->nullable()->constrained($this->table())->cascadeOnDelete();

            $table->string('name', 500)->nullable();
            $table->string('extension', 12)->nullable();

            // Уникальный отпечаток файла (имя + размер + дата изменения)
            $table->string('file_fingerprint')->nullable();
            $table->string('source', 500)->nullable()->index();

            // Данные кэша Gemini
            $table->string('gemini_cache_id')->nullable();
            $table->string('gemini_rules_hash')->nullable();
            $table->timestamp('gemini_cache_expires_at')->nullable();

            $table->json('options')->nullable();
            // varchar(40): самый длинный статус — estimate_analysis_failed (24 символа)
            $table->string('status', 40)->default(AnalysisStatus::Preparing->value);
            $table->dateTime('completed_at')->nullable();

            // Единый error-блок: учёт попыток, текст/время ошибки, backoff, терминальность
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('failed_permanently')->default(false);

            // Лизинг: атомарный захват записи на время обработки стадии (Phase 2)
            $table->string('locked_by')->nullable();
            $table->timestamp('locked_until')->nullable();

            $table->timestamps();

            // Идемпотентность сессии анализа (firstOrCreate по владельцу+источнику)
            $table->unique(['model_type', 'model_id', 'source'], 'plt_doc_analysis_owner_source_uniq');
            // Индексы под discover (retry) и stuck-check (по updated_at)
            $table->index(['status', 'next_retry_at'], 'plt_doc_analysis_status_retry_idx');
            $table->index(['status', 'updated_at'], 'plt_doc_analysis_status_updated_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new DocumentAnalysis())->getTable();
    }
};
