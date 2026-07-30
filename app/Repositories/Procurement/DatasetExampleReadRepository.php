<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\DatasetExample;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Чтение `prc_ai_dataset_examples` (`root/service-layer-map.md` §6) — `estimate:eval:run`, `estimate:dataset:export`.
 */
final readonly class DatasetExampleReadRepository
{
    /**
     * @param  list<string>  $holdoutCodes
     * @return Collection<int, DatasetExample>
     */
    public function forTask(string $task, string $split, array $holdoutCodes, ?int $limit): Collection
    {
        $query = DatasetExample::query()->where('task', $task)->with('documentVersion');

        if ($split !== 'all') {
            $query->whereHas('fixture', function (Builder $fixtureQuery) use ($holdoutCodes, $split): void {
                $split === 'holdout'
                    ? $fixtureQuery->whereIn('code', $holdoutCodes)
                    : $fixtureQuery->whereNotIn('code', $holdoutCodes);
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @return Builder<DatasetExample>
     */
    public function goldExamples(string $task): Builder
    {
        return DatasetExample::query()->where('task', $task);
    }
}
