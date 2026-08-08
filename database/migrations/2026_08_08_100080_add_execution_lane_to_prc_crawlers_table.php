<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Execution lane профиля (P4.7, D117): `standard` остаётся безопасным default для всех
 * существующих записей, а временный архив получает отдельный конечный lease через `long`.
 * Колонка хранит стабильный продуктовый enum, не инфраструктурные секунды timeout/retry_after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('execution_lane')->default('standard');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('execution_lane');
        });
    }

    private function table(): string
    {
        return (new Crawler)->getTable();
    }
};
