<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\DaemonTick;
use App\Enums\Procurement\Process\DaemonKind;

/**
 * Один tick долгоживущего Process daemon (bp-daemons). Цикл, signals, heartbeat, backoff и recycle
 * принадлежат DaemonLoop; daemon знает только свою работу и свой интервал. tick не бросает ради
 * штатной деградации (publish/feed failure возвращается кодом ошибки), неожиданное исключение
 * loop классифицирует сам.
 */
interface ProcessDaemon
{
    public function kind(): DaemonKind;

    public function tick(): DaemonTick;

    /** Пауза до следующего tick в миллисекундах; 0 — работа есть, drain без sleep. */
    public function delayMs(DaemonTick $last): int;
}
