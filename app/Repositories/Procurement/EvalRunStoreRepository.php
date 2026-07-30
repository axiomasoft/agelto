<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;

/**
 * Запись `prc_ai_eval_runs`/`prc_ai_eval_results` (`root/service-layer-map.md` §6) — `estimate:eval:run`.
 */
final readonly class EvalRunStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function start(array $attributes): EvalRun
    {
        return EvalRun::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function finish(EvalRun $run, array $attributes): void
    {
        $run->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeResult(array $attributes): EvalResult
    {
        return EvalResult::query()->create($attributes);
    }
}
