<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

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
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('completed_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
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
