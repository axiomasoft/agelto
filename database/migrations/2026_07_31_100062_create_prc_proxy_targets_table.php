<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Носитель per-target здоровья прокси (research/02 §3.2): одна строка на пару (узел, цель),
 * EWMA успешности/задержки вместо оконного агрегата по `prc_fetches` в горячем пути.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proxy_id')
                ->constrained(table: (new Proxy)->getTable())
                ->cascadeOnDelete();

            $table->text('target')->comment('канонический хост цели, не URL (zakupki.gov.ru)');

            $table->double('success_ratio')->default(1.0)->comment('EWMA успешности; 1.0 у нового узла — получает шанс');
            $table->integer('latency_ms_avg')->nullable()->comment('EWMA длительности успешного запроса; NULL = замеров не было');
            $table->integer('consecutive_failures')->default(0)->comment('серия отказов НА ЭТОЙ цели');

            $table->timestampTz('open_until')->nullable()->comment('breaker уровня (узел, цель)');
            $table->smallInteger('open_count')->default(0)->comment('степень экспоненциального cooldown');
            $table->text('last_failure_reason')->nullable()->comment('значение App\Enums\Procurement\Proxy\ProxyFailureReason');
            $table->timestampTz('last_outcome_at')->nullable();

            $table->timestamps();

            $table->unique(['proxy_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new ProxyTarget)->getTable();
    }
};
