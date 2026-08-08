<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D41: `is_active` больше не исключительно ручной тумблер — глобальный breaker может
 * отключить узел, а оператор возвращает его через явное действие с полным сбросом состояния.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->timestampTz('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['disabled_at', 'disabled_reason']);
        });
    }

    private function table(): string
    {
        return (new Proxy)->getTable();
    }
};
