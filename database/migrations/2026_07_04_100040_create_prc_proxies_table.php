<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Прокси как слой маршрутизации (research/02 §3.1, D4, D7): типизированные scopes вместо
 * `targets`, страна и глобальное здоровье/breaker. D41: глобальный breaker может отключить узел,
 * оператор возвращает его явным действием с полным сбросом состояния.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->text('service');
            $table->text('host');
            $table->integer('port')->nullable();
            $table->text('username')->nullable();
            // Шифруется cast'ом 'encrypted' модели Proxy (Crypt, APP_KEY); text вмещает шифртекст.
            $table->text('password')->nullable();
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2, верхний регистр; единственный носитель гео (D5)');
            $table->jsonb('scopes')->default('[]')->comment('список значений App\Enums\Procurement\Proxy\ProxyScope; пустой = узел не выдаётся ни для одного скоупа');
            $table->timestampTz('open_until')->nullable()->comment('breaker: момент, до которого узел глобально выведен из выдачи; NULL/прошлое = доступен');
            $table->smallInteger('open_count')->default(0)->comment('число открытий breaker без успешной пробы');
            $table->text('last_failure_reason')->nullable()->comment('последний глобальный App\Enums\Procurement\Proxy\ProxyFailureReason');
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('fails_count')->default(0);
            $table->timestampTz('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'country']);
        });

        DB::statement("CREATE INDEX prc_proxies_scopes_gin ON {$this->table()} USING GIN (scopes)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_proxies_scopes_gin');
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (new Proxy)->getTable();
    }
};
