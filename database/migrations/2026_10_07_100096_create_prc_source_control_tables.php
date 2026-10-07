<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контур управления источником: брейкер на (площадка, этап) (`prc_source_circuits`; этапы
 * листинга и карточек независимы, пороги редактируются в админке) и журнал инцидентов (`prc_source_incidents`:
 * пропущенные блоки и карточки, ожидания этапов, ручные действия). Журнал — не очередь
 * повторной обработки: он фиксирует, ЧТО и ГДЕ пропущено, чтобы разобрать это вручную.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new SourceCircuit)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained(table: (new Platform)->getTable())->cascadeOnDelete();
            $table->text('stage')->comment('listing | detail — у каждого этапа свой брейкер');
            $table->boolean('enabled')->default(true);
            $table->boolean('manually_paused')->default(false);
            $table->integer('failure_threshold')->nullable()->comment('Переопределение порога отказов до ожидания; null — из config crawler.source_control');
            $table->integer('layout_threshold')->nullable()->comment('Переопределение порога отказов структуры до остановки; null — из конфига');
            $table->integer('retry_interval_minutes')->nullable()->comment('Переопределение интервала ожидания; null — из конфига');
            $table->integer('consecutive_failures')->default(0)->comment('Счётчик реакции CountTowardWait');
            $table->integer('consecutive_layout_failures')->default(0)->comment('Счётчик реакции CountTowardHold');
            $table->timestampTz('retry_at')->nullable()->comment('Ожидание до этого момента; null — работает');
            $table->timestampTz('held_at')->nullable()->comment('Остановлен из-за структуры до решения оператора');
            $table->text('last_failure_kind')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->text('changed_by')->nullable()->comment('Кто последним менял состояние: system или оператор');
            $table->timestampsTz();
            $table->unique(['platform_id', 'stage']);
        });

        Schema::create((new SourceIncident)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained(table: (new Platform)->getTable())->cascadeOnDelete();
            $table->foreignId('crawler_id')->nullable()->constrained(table: (new Crawler)->getTable())->nullOnDelete();
            $table->unsignedBigInteger('run_id')->nullable()->comment('prc_crawler_runs.id; без FK — журнал переживает чистку прогонов');
            $table->unsignedBigInteger('fetch_id')->nullable()->comment('prc_fetches.id (партиционирована, без FK)');
            $table->text('type');
            $table->text('severity');
            $table->text('failure_kind')->nullable();
            $table->integer('http_status')->nullable();
            $table->jsonb('cursor')->nullable()->comment('Курсор прогона в момент инцидента');
            $table->jsonb('request_params')->nullable()->comment('Параметры запроса блока: где именно пропуск');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->text('actor')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledged_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['platform_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new SourceIncident)->getTable());
        Schema::dropIfExists((new SourceCircuit)->getTable());
    }
};
