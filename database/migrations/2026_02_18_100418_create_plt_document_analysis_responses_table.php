<?php

declare(strict_types=1);

use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Models\DocumentAnalysis\DocumentAnalysisResponse;
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
            $table->foreignId('document_analysis_id')->constrained((new DocumentAnalysis())->getTable())->cascadeOnDelete();

            // для учета стадий анализа
            $table->foreignId('parent_id')->nullable()->constrained($this->table())->cascadeOnDelete();

            // что давали на вход
            $table->text('prompt');

            // Тип действия (primary_analysis, detailed_parsing, etc.)
            $table->string('step')->nullable();

            // СВЯЗКА С КЭШЕМ
            // Пишем ID кэша, который использовался в момент этого запроса
            $table->string('cache_file_id')->nullable()->index();
            // Хеш инструкций, чтобы знать, какие промпты были актуальны для этого ответа
            $table->string('rules_hash')->nullable();

            // ответ от ИИ
            $table->boolean('success')->default(true);
            $table->string('message')->nullable();
            $table->json('data')->nullable();

            // Хранить состояние процесса анализа
            // Например первичный анализ показал, что есть сметы
            // Для каждой сметы нужно отправлять отдельные запросы для получения элементов сметы
            // подвала и так для каждой сметы внутри
            $table->json('state')->nullable();

            $table->timestamps();
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
        return (new DocumentAnalysisResponse())->getTable();
    }
};
