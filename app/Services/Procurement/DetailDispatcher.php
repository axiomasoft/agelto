<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Jobs\Procurement\FetchProcurementDetailJob;

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
    ) {}

    public function dispatchDue(int $limit): int
    {
        $procurements = $this->selector->due(limit: $limit);
        $dispatched = 0;

        foreach ($procurements as $procurement) {
            FetchProcurementDetailJob::dispatch(procurementId: $procurement->id)->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }
}
