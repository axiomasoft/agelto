<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Analyzers\Estimate;

use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Support\LazyCollection;

/** Retained legacy raw-address maintenance only; accepted estimates are written by PublicationRepository. */
final readonly class EstimateStoreRepository
{
    /**
     * Сметы с вынесенным сырым результатом (`raw_result_ref IS NOT NULL`) по возрастанию `id`,
     * начиная с `$fromId` — read-путь backfill'а дисков (`research/07-storage-addressing.md`
     * §6): строки с inline `raw_result` не носят файла и backfill'у не подлежат.
     *
     * @return LazyCollection<int, Estimate>
     */
    public function withRawResultRefFromId(int $fromId): LazyCollection
    {
        return Estimate::query()
            ->where('id', '>=', $fromId)
            ->whereNotNull('raw_result_ref')
            ->orderBy('id')
            ->select(['id', 'raw_result_disk', 'raw_result_ref'])
            ->cursor();
    }

    /**
     * Точечная запись диска сырого результата — write-путь backfill'а, симметрично
     * писателю `EstimatePersister::rawResultAttributes`.
     */
    public function updateRawResultDisk(int $id, string $disk): void
    {
        Estimate::query()->whereKey($id)->update(['raw_result_disk' => $disk]);
    }
}
