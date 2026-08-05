<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P7.5, находка F-04 из research/08-db-review.md: `prc_revisions.crawler_run_id` не
 * несла FK на `prc_crawler_runs`, хотя цель не партиционирована и FK технически
 * возможен — провенанс ревизии («каким прогоном обнаружена») ничем не защищён.
 * Индекс не нужен (правило 3.2 находки — колонка write-only, читателей в коде нет).
 * `prc_revisions` партиционирована (3 партиции, 25 МБ, 17 942 строки) — Postgres 17 не
 * поддерживает `NOT VALID` при добавлении FK на партиционированный родитель
 * (`SQLSTATE[42809]`, проверено на дереве), поэтому констрейнт добавляется обычным
 * `ADD CONSTRAINT` (проверка на боевом объёме — доли секунды, не долгая блокировка).
 */
return new class extends Migration
{
    public function up(): void
    {
        $revisions = (new ProcurementRevision)->getTable();
        $crawlerRuns = (new Run)->getTable();

        DB::statement(
            "ALTER TABLE {$revisions} ADD CONSTRAINT {$revisions}_crawler_run_id_foreign "
            ."FOREIGN KEY (crawler_run_id) REFERENCES {$crawlerRuns}(id) ON DELETE NO ACTION",
        );
    }

    public function down(): void
    {
        $revisions = (new ProcurementRevision)->getTable();

        DB::statement("ALTER TABLE {$revisions} DROP CONSTRAINT {$revisions}_crawler_run_id_foreign");
    }
};
