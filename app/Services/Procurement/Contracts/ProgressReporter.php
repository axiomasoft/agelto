<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Services\Procurement\Contracts\Dto\ProgressTick;

/**
 * Сейм прогресса сервисного слоя (§2 `root/service-layer-map.md`, P3.1): передаётся ОПЦИОНАЛЬНЫМ
 * параметром МЕТОДА длинной операции (`run(..., ?ProgressReporter $progress = null)`), не
 * конструктора — репортёр является свойством ВЫЗОВА, а не сервиса.
 */
interface ProgressReporter
{
    public function report(ProgressTick $tick): void;
}
