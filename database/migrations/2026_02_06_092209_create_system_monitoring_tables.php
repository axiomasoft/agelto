<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Таблица логов
        Schema::create('sys_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20); // error, critical, warning
            $table->string('stage', 20); // stage_1, stage_2, etc
            $table->string('source');    // Класс или команда
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['stage', 'level']);
        });

        // Таблица аналитики
        Schema::create('platform_parser_analytics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('parser_id')->constrained('platform_parsers')->cascadeOnDelete();

            $table->integer('s1_found')->default(0);
            $table->integer('s1_errors')->default(0);

            $table->integer('s2_docs_indexed')->default(0);
            $table->integer('s2_errors')->default(0);

            $table->integer('s3_ai_processed')->default(0);
            $table->integer('s3_smeta_found')->default(0);
            $table->integer('s3_errors')->default(0);

            $table->integer('s4_parsed_items')->default(0);
            $table->integer('s4_errors')->default(0);

            $table->unique(['date', 'parser_id'], 'parser_date_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_parser_analytics');
        Schema::dropIfExists('sys_logs');
    }
};
