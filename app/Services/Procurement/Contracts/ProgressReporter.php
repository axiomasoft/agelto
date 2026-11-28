<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Services\Procurement\Contracts\Dto\ProgressTick;

/**
 * Опциональный reporter длинной сервисной операции. Передаётся параметром метода,
 * например run(..., ?ProgressReporter $progress=null), поскольку относится к вызову,
 * а не к сроку жизни сервиса.
 */
interface ProgressReporter
{
    public function report(ProgressTick $tick): void;
}
