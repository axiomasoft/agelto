<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\ProcessValue;

/**
 * Итог одного tick daemon: сколько единиц работы обработано и текущий bounded код ошибки
 * (readiness, не exception). Ошибка не прекращает loop и не выдаётся за успех: heartbeat
 * сохраняет код, пока следующий tick не пройдёт без него.
 */
final readonly class DaemonTick
{
    public function __construct(public int $processed, public ?string $errorCode = null)
    {
        ProcessValue::nonNegative($processed);
        if ($errorCode !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Daemon error code must be bounded lower_snake_case.');
        }
    }

    public static function idle(): self
    {
        return new self(0);
    }

    public function degraded(): bool
    {
        return $this->errorCode !== null;
    }
}
