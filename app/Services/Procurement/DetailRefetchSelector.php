<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
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
     * @param  list<int>  $excludePlatformIds  площадки, где этап карточек сейчас не пускает запросы
     * @return LazyCollection<int, Procurement>
     */
    public function due(int $limit, array $excludePlatformIds = []): LazyCollection
    {
        $rescanIntervalMinutes = config('crawler.detail_rescan_interval_minutes', 60);

        if (! is_int($rescanIntervalMinutes)) {
            throw new \UnexpectedValueException('crawler.detail_rescan_interval_minutes must be an integer.');
        }

        $rescanBefore = Carbon::now()->subMinutes($rescanIntervalMinutes);

        $query = Procurement::query()
            ->where('detail_failed', false)
            ->when($excludePlatformIds !== [], fn ($query) => $query->whereNotIn(
                'registry_id',
                Registry::query()->select('id')->whereIn('platform_id', $excludePlatformIds),
            ))
            ->where(function ($query): void {
                $query->whereNull('detail_next_retry_at')
                    ->orWhere('detail_next_retry_at', '<=', now());
            })
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
            });

        /** @var literal-string $table */
        $table = (new Procurement)->getTable();
        /** @var literal-string $registries */
        $registries = (new Registry)->getTable();
        $platformCount = max(1, Registry::query()->distinct()->count('platform_id'));
        $ranked = $query->select("{$table}.*")
            ->join($registries, "{$registries}.id", '=', "{$table}.registry_id")
            ->selectRaw("row_number() OVER (PARTITION BY {$registries}.platform_id ORDER BY CASE WHEN detail_fetched_at IS NULL THEN 0 ELSE 1 END, detail_fetched_at, {$table}.id) AS source_fair_rank")
            ->selectRaw("dense_rank() OVER (ORDER BY {$registries}.platform_id) AS source_platform_rank");

        // Each round serves one record per platform. Rotate ties when the global limit is
        // smaller than the number of platforms; a fixed platform ID must not win every tick.
        return Procurement::query()->fromSub($ranked, $table)
            ->orderBy('source_fair_rank')
            ->orderByRaw('mod(source_platform_rank + ?, ?)', [intdiv(now()->getTimestamp(), 60), $platformCount])
            ->orderBy('id')->limit(max(0, min(1000, $limit)))->cursor();
    }
}
