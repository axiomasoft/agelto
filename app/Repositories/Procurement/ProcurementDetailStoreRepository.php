<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Support\Facades\DB;

/**
 * Запись детального слоя карточки закупки (`prc_eis_details`/`prc_financing`/`prc_items`)
 * для tab-процессоров P3.3/P3.4/P3.6/P3.7. Ни один из процессоров не мутирует эти таблицы
 * напрямую через Eloquent — только через этот репозиторий.
 *
 * `prc_financing` и `prc_items` НЕ имеют уникальных индексов на естественные ключи
 * (`(procurement_id, fiscal_year, kind)` / `(procurement_id, position_number)` —
 * `01-database.md` §3, миграции `2026_07_04_100016`/`2026_07_04_100015`), поэтому:
 * - финансирование — replace-set (delete всех строк закупки + insert актуальных);
 * - позиции — `updateOrCreate` по составному естественному ключу (без `ON CONFLICT`).
 */
final readonly class ProcurementDetailStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeEisDetails(int $procurementId, array $attributes): void
    {
        EisDetails::query()->updateOrCreate(
            ['procurement_id' => $procurementId],
            $attributes,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceFinancing(int $procurementId, array $rows): void
    {
        DB::transaction(function () use ($procurementId, $rows): void {
            Financing::query()->where('procurement_id', $procurementId)->delete();

            foreach ($rows as $row) {
                Financing::query()->create([...$row, 'procurement_id' => $procurementId]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertItem(int $procurementId, array $attributes): void
    {
        PurchaseItem::query()->updateOrCreate(
            [
                'procurement_id' => $procurementId,
                'position_number' => $attributes['position_number'],
            ],
            $attributes,
        );
    }
}
