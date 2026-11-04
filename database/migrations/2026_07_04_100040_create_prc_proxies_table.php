<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Конфигурация proxy node: адрес, credentials metadata, страна и типизированные scopes.
 * Health и административное enabled-состояние маршрута принадлежат network authority,
 * а scopes описывают назначение запросов, для которых узел разрешён.
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
            $table->char('country', 2)->nullable()->comment('ISO 3166-1 alpha-2, верхний регистр; единственный носитель гео (D5)');
            $table->jsonb('scopes')->default('[]')->comment('список значений App\Enums\Procurement\Proxy\ProxyScope; пустой = узел не выдаётся ни для одного скоупа');
            $table->timestamps();

            $table->index('country');
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
