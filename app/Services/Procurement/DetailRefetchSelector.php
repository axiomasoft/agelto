<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Триггеры пересъёма детальной карточки (`02-crawling.md` §5.2): (1) закупка ещё ни разу
 * не снята; (2) новая редакция ПОСЛЕ последнего снятия — покрывает и смену этапа, т.к.
 * канонический статус входит в снапшот ревизии (`ProcurementIngestProcessor`), т.е. любая
 * смена этапа гарантированно бьёт `current_revision_detected_at`; (3) периодический ре-скан
 * закупок на завершающихся этапах до появления итогов; (4) ретрай по `detail_next_retry_at`.
 * `detail_failed=true` (ретраи исчерпаны) — вне автоматического подбора, требует явного
 * вызова `crawler:fetch-details {procurement}`.
 */
final readonly class DetailRefetchSelector
{
    private const RESCAN_STATUSES = [ProcurementStatus::Evaluation, ProcurementStatus::Awarded];

    /**
     * @return LazyCollection<int, Procurement>
     */
    public function due(): LazyCollection
    {
        $rescanBefore = Carbon::now()->subMinutes((int) config('crawler.detail_rescan_interval_minutes', 60));

        return Procurement::query()
            ->where('detail_failed', false)
            ->where(function ($query) use ($rescanBefore): void {
                $query->whereNull('detail_fetched_at')
                    ->orWhereColumn('current_revision_detected_at', '>', 'detail_fetched_at')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('detail_next_retry_at')
                            ->where('detail_next_retry_at', '<=', now());
                    })
                    ->orWhere(function ($query) use ($rescanBefore): void {
                        $query->whereIn('status', self::RESCAN_STATUSES)
                            ->where('detail_fetched_at', '<=', $rescanBefore);
                    });
            })
            ->lazy();
    }
}
