<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прокси профиля (research/02 §3.3, D80): `use_proxy` задаёт намеренный прямой режим, страна
 * ограничивает выбор узла; скоуп берётся от точки исхода. Политика остановки P3.3 хранит
 * продуктовый режим, execution lane P4.7/D117 — стабильный enum очереди, не секунды lease.
 * P7.5 F-02: связь с реестром сохраняет прежний `NO ACTION` явно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('registry_id')
                ->index()
                ->constrained(table: (new Registry)->getTable())
                ->onDelete('NO ACTION');

            $table->text('name');
            $table->text('mode');
            $table->text('source_method');
            $table->text('fallback_method')->nullable();

            $table->jsonb('filter')->nullable()->comment('даты/цены/ОКПД2/этапы');
            $table->text('schedule')->nullable()->comment('cron-выражение');
            $table->jsonb('options')->nullable()->comment('recordsPerPage, лимиты дробления, колонки CSV');

            $table->uuid('route_profile_id')->nullable();
            $table->text('stop_policy')->default('default');
            $table->text('execution_lane')->default('standard');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Crawler)->getTable();
    }
};
