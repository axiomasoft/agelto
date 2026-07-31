<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка профиля обхода к прокси (research/02 §3.3, D80): использовать/не использовать
 * прокси и желаемая страна узла ДЛЯ ЭТОГО профиля. Колонки скоупа на профиле нет —
 * скоуп берётся от точки исхода (D80).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->boolean('use_proxy')->default(true)->comment('прямой запрос при false — намеренный режим, не отказ');
            $table->char('proxy_country', 2)->nullable()->comment('ISO 3166-1 alpha-2; NULL = профиль страну не ограничивает');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['use_proxy', 'proxy_country']);
        });
    }

    private function table(): string
    {
        return (new Crawler)->getTable();
    }
};
