<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\History;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Все запросы к секционированным журналам требуют окно доменного времени в сигнатуре. */
final readonly class ProcurementHistoryReadRepository
{
    /** @return LengthAwarePaginator<int, ProcurementEvent> */
    public function paginateEvents(Procurement $procurement, Carbon $from, Carbon $until, int $perPage = 25): LengthAwarePaginator
    {
        return $procurement->events()->whereBetween('occurred_at', [$from, $until])->paginate(perPage: $perPage);
    }

    /** @return LengthAwarePaginator<int, ProcurementRevision> */
    public function paginateRevisions(Procurement $procurement, Carbon $from, Carbon $until, int $perPage = 25): LengthAwarePaginator
    {
        return $procurement->revisions()->whereBetween('detected_at', [$from, $until])->paginate(perPage: $perPage);
    }

    /** @return Collection<int, ProcurementEvent> */
    public function eventsForTimeline(Procurement $procurement, Carbon $from, Carbon $until): Collection
    {
        return $procurement->events()->whereBetween('occurred_at', [$from, $until])->get();
    }

    /** @return Collection<int, ProcurementRevision> */
    public function revisionsForTimeline(Procurement $procurement, Carbon $from, Carbon $until): Collection
    {
        return $procurement->revisions()->whereBetween('detected_at', [$from, $until])->get();
    }
}
