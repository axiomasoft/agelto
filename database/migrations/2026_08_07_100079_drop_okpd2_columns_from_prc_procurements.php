<?php

declare(strict_types=1);

use App\Models\Procurement\Procurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Снос мёртвой ветки ОКПД2 листинга (D16, P2.6): `okpd2_code`/`okpd2_codes` на боевых данных
 * ВСЕГДА `null` — колонка «Классификация по ОКПД2» в CSV-экспорте ЕИС приходит пустой в двух
 * независимых замерах (`findings/P1-okpd2-probe.md` §4, `tests/Unit/Crawler/
 * EisCsvRowMapperTest.php:179–182`). Принадлежность подмножеству ОКПД2 гарантирует серверный
 * фильтр профиля (P2.2–P2.3); второй носитель того же факта не нужен. `down()` восстанавливает
 * обе колонки и GIN-индекс в прежнем виде.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS prc_procurements_okpd2_codes_gin');

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['okpd2_code', 'okpd2_codes']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->text('okpd2_code')->nullable()->index();
        });

        DB::statement('ALTER TABLE prc_procurements ADD COLUMN okpd2_codes text[]');
        DB::statement('CREATE INDEX prc_procurements_okpd2_codes_gin ON prc_procurements USING GIN (okpd2_codes)');
    }

    private function table(): string
    {
        return (new Procurement)->getTable();
    }
};
