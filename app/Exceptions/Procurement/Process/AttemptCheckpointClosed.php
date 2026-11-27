<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/** Откат checkpoint: attempt уже закрыт. Не QueryException, поэтому ProcessTransaction не повторяет closure. */
final class AttemptCheckpointClosed extends \RuntimeException {}
