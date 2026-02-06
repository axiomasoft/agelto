<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Стадия 1: Ссылки
        Schema::create('platform_parser_map_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parser_id')->constrained('platform_parsers')->cascadeOnDelete();
            $table->string('reg_number')->unique();
            $table->string('detail_url', 500);

            // Даты из карточки
            $table->dateTime('published_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('submission_end_at')->nullable();

            // Состояние
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();
            $table->index(['status', 'next_retry_at']);
        });

        // Стадия 2 и 3: Документы
        Schema::create('platform_parser_map_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_url_id')->constrained('platform_parser_map_urls')->cascadeOnDelete();
            $table->string('name', 500);
            $table->string('url', 1000);

            // Статус загрузки и общего процесса
            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Стадия 3: ИИ Скрининг
            $table->string('ai_status', 20)->default('pending'); // success, failed
            $table->text('ai_error')->nullable();
            $table->json('ai_analysis_report')->nullable(); // Вердикт: is_smeta, total_sum

            $table->timestamp('parse_started_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_parser_map_documents');
        Schema::dropIfExists('platform_parser_map_urls');
    }
};
