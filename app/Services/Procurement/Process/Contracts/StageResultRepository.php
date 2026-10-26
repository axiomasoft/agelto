<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkInput;

/**
 * Stage-specific finalization repository, привязанный через StageRegistry (execution#s03). Обе
 * операции вызываются только внутри уже fenced Process transaction после ownership/input guards,
 * под полным prefix: explicit SQL на уже locked subject rows, без HTTP, sleep, Eloquent lazy-load
 * и без собственной транзакции. Ошибка записи откатывает весь checkpoint/finalize.
 */
interface StageResultRepository
{
    /** Domain progress (cursor/part/chunk refs) вместе с guarded work checkpoint. */
    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void;

    /**
     * Validated domain result; возвращает typed inputs ready successors. Ledger создаёт их
     * идемпотентно по work_key и связывает с runs текущей work.
     *
     * @return list<WorkInput>
     */
    public function storeResult(WorkInput $input, ValidatedStageResult $result): array;
}
