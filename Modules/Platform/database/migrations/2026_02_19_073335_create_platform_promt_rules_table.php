<?php

declare(strict_types=1);

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
        Schema::create('platform_promt_rules', function (Blueprint $table) {
            $table->id();

            // Уникальный ключ правила (например, 'header_metadata')
            $table->string('key')->index();

            // Группа: первичный анализ, детальный парсинг и т.д.
            $table->string('group')->index();

            // Само содержание инструкции
            $table->text('content');

            // Версия для возможности отката
            $table->integer('version')->default(1);

            // Приоритет (чем выше, тем ниже в итоговом промпте)
            $table->integer('priority')->default(100);

            // Флаг активности правила
            $table->boolean('is_active')->default(true);

            // Составной индекс для быстрого поиска текущих правил группы
            $table->unique(['key', 'version']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_promt_rules');
    }
};
