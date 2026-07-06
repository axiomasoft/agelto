<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Award;
use App\Models\Procurement\Bid;
use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\Protocol;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Support\Facades\DB;

/**
 * Запись детального слоя карточки закупки (`prc_eis_details`/`prc_financing`/`prc_items`/
 * `prc_protocols`/`prc_bids`/`prc_awards`) для tab-процессоров P3.3/P3.4/P3.6/P3.7. Ни один
 * из процессоров не мутирует эти таблицы напрямую через Eloquent — только через этот
 * репозиторий.
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

    /**
     * Upsert по естественному ключу `(procurement_id, source_number)` (`01-database.md` §4,
     * §5.2). `source_number` может быть `null` (протокол без явного номера в источнике) —
     * тогда естественный ключ вырождается в «единственный протокол закупки без номера».
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertProtocol(int $procurementId, ?string $sourceNumber, array $attributes): Protocol
    {
        return Protocol::query()->updateOrCreate(
            [
                'procurement_id' => $procurementId,
                'source_number' => $sourceNumber,
            ],
            $attributes,
        );
    }

    /**
     * Upsert по `(procurement_id, protocol_id, organization_id)` — участник встречается
     * ровно один раз в рамках одного протокола закупки (`01-database.md` §4).
     *
     * `organizationId === null` — участник без надёжного идентификатора (ИНН/ОГРН),
     * `SupplierResultsParser` намеренно не резолвит его через `OrganizationResolver`
     * (см. `BidData`). Естественный ключ `(…, organization_id: null)` совпал бы у ВСЕХ
     * таких участников закупки и `updateOrCreate` схлопнул бы их в одну строку `prc_bids` —
     * поэтому в этом случае ключ вместо `organization_id` использует `participant_ordinal`
     * (порядковый номер по рассмотрению, всегда присутствует в таблице результатов).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertBid(
        int $procurementId,
        ?int $protocolId,
        ?int $organizationId,
        ?int $participantOrdinal,
        array $attributes,
    ): Bid {
        $key = [
            'procurement_id' => $procurementId,
            'protocol_id' => $protocolId,
            'organization_id' => $organizationId,
        ];

        if ($organizationId === null) {
            $key['participant_ordinal'] = $participantOrdinal;
        }

        return Bid::query()->updateOrCreate($key, $attributes);
    }

    /**
     * Upsert по `(procurement_id, lot_id)` — один итог на лот (44-ФЗ виртуальный лот
     * `null`, `01-database.md` §4, §5.2).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertAward(int $procurementId, ?int $lotId, array $attributes): Award
    {
        return Award::query()->updateOrCreate(
            [
                'procurement_id' => $procurementId,
                'lot_id' => $lotId,
            ],
            $attributes,
        );
    }
}
