<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Crawler\SourceStage;
use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;
use Illuminate\Support\Carbon;

/**
 * Подаёт ограниченную детерминированную пачку due-закупок в detail-очередь.
 *
 * Повторный тик может увидеть ту же закупку, но второй job не попадёт в очередь благодаря
 * `FetchProcurementDetailJob::ShouldBeUnique`; выборка не мутирует доменный retry-контур.
 */
final readonly class DetailDispatcher
{
    public function __construct(
        private DetailRefetchSelector $selector,
        private SourceCircuitRepository $circuits,
    ) {}

    public function dispatchDue(int $limit): int
    {
        if (! config('crawler.downstream_enabled', true)) {
            return 0;
        }

        // Этап карточек площадки ждёт или стоит: job всё равно вернулся бы без запроса, а очередь
        // каждый тик наполнялась бы холостыми job и занимала лимит других площадок (найдено
        // сценарием). Такие закупки остаются due и уйдут после возобновления этапа.
        $procurements = $this->selector->due(
            limit: $limit,
            excludePlatformIds: $this->circuits->notAdmittingPlatformIds(SourceStage::Detail, Carbon::now()),
        );
        $dispatched = 0;

        foreach ($procurements as $procurement) {
            FetchProcurementDetailJob::dispatch(procurementId: $procurement->id)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }
}
