<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Последний измеримый исход разбора карточки, перезаписываемый при каждом успешном снятии.
 * История не нужна: суточный healthcheck отбирает закупки по `detail_fetched_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prc_procurements', function (Blueprint $table): void {
            $table->jsonb('detail_parse_outcome')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prc_procurements', function (Blueprint $table): void {
            $table->dropColumn('detail_parse_outcome');
        });
    }
};
