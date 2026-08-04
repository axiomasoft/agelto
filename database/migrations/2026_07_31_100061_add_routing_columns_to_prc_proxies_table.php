<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Прокси как слой маршрутизации (research/02 §3.1, D4, D7): типизированный скоуп вместо
 * свободного `targets(jsonb)`, страна и носители глобального здоровья/breaker'а.
 * `is_active` остаётся, но становится ИСКЛЮЧИТЕЛЬНО ручным тумблером оператора — автоматика
 * работает через `open_until`/`open_count` (P1.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2, верхний регистр; единственный носитель гео (D5)');
            $table->jsonb('scopes')->default('[]')->comment('список значений App\Enums\Procurement\Proxy\ProxyScope; пустой = узел не выдаётся ни для одного скоупа');
            $table->timestampTz('open_until')->nullable()->comment('breaker: момент, до которого узел глобально выведен из выдачи; NULL/прошлое = доступен');
            $table->smallInteger('open_count')->default(0)->comment('сколько раз подряд breaker открывался без успешной пробы — степень экспоненциального cooldown');
            $table->text('last_failure_reason')->nullable()->comment('значение App\Enums\Procurement\Proxy\ProxyFailureReason последнего глобального отказа');
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();

            $table->index(['is_active', 'country']);
            $table->dropColumn('targets');
        });

        DB::statement("CREATE INDEX prc_proxies_scopes_gin ON {$this->table()} USING GIN (scopes)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_proxies_scopes_gin');

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->jsonb('targets')->nullable();
            $table->dropIndex(['is_active', 'country']);
            $table->dropColumn([
                'country',
                'scopes',
                'open_until',
                'open_count',
                'last_failure_reason',
                'last_success_at',
                'last_failure_at',
            ]);
        });
    }

    private function table(): string
    {
        return (new Proxy)->getTable();
    }
};
