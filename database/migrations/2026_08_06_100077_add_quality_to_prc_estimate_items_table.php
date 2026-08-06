<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Носитель неопределённости уровня ПОЗИЦИИ (P8.4, research/12-uncertainty-model.md §3.2, D136) +
// минимальный носитель уровня СМЕТЫ (§5, D155) — обе колонки аддитивны, nullable, без backfill:
// боевые данные уже есть (прогон P4), но `prc_estimate_items` на живой БД содержит 0 строк
// (`findings/P7-schema-inventory.md:208`) — `migrate:fresh` для НЕЁ конкретно всё ещё дёшев.
// Частичный индекс — единственный названный потребитель: фильтр/счётчик «только помеченные»
// витрины P8.5 (D74).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Item)->getTable(), function (Blueprint $table): void {
            $table->jsonb('quality')->nullable()
                ->comment('провенанс-ключ верхнего уровня model/validator — research/12-uncertainty-model.md §3.2');
        });

        DB::statement(sprintf(
            'create index %1$s_quality_index on %1$s (estimate_id) where quality is not null',
            (new Item)->getTable(),
        ));

        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->jsonb('validation_notes')->nullable()
                ->comment('ValidationReport::$notes сметы целиком — research/12-uncertainty-model.md §5');
        });
    }

    public function down(): void
    {
        Schema::table((new Estimate)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('validation_notes');
        });

        DB::statement(sprintf('drop index if exists %s_quality_index', (new Item)->getTable()));

        Schema::table((new Item)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('quality');
        });
    }
};
