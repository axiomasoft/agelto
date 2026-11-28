<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/** Откат heartbeat: attempt уже закрыт. Не QueryException, поэтому ProcessTransaction не повторяет closure. */
final class AttemptHeartbeatClosed extends \RuntimeException {}
