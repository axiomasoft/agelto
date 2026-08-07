<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Политика остановки fresh-прогона (P3.3, `research/04-p3-runs-design.md` §4–§5): `default`
 * останавливается только на блоке без изменений/повторе `dedupSignature`, `only_fresh` —
 * дополнительно на времени старта предыдущего завершённого прогона. NOT NULL с дефолтом —
 * третье состояние «не задано» не нужно, существующие профили молча остаются на `default`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('stop_policy')->default('default');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('stop_policy');
        });
    }

    private function table(): string
    {
        return (new Crawler)->getTable();
    }
};
