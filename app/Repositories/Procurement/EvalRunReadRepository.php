<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\EvalRun;

/**
 * Чтение `prc_ai_eval_runs` (`root/service-layer-map.md` §6) — `estimate:eval:compare`.
 */
final readonly class EvalRunReadRepository
{
    public function find(int $id): ?EvalRun
    {
        return EvalRun::query()->find($id);
    }
}
