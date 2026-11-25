<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\DaemonTick;
use App\Enums\Procurement\Process\DaemonKind;
use Closure;

/**
 * Один tick долгоживущего Process daemon. Цикл, signals, heartbeat, backoff и recycle
 * принадлежат DaemonLoop; daemon знает только свою работу и свой интервал. tick не бросает ради
 * штатной деградации (publish/feed failure возвращается кодом ошибки), неожиданное исключение
 * loop классифицирует сам.
 */
interface ProcessDaemon
{
    public function kind(): DaemonKind;

    /** @param (Closure(): void)|null $heartbeat Проверяет интервал между частями долгого tick. */
    public function tick(?Closure $heartbeat = null): DaemonTick;

    /** Пауза до следующего tick в миллисекундах; 0 — работа есть, drain без sleep. */
    public function delayMs(DaemonTick $last): int;
}
