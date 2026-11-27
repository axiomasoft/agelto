<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/**
 * Typed недоступность зависимости (DB connection/resource/timeout): не Lost и не programming
 * fault. Новые external effects не начинаются (fail closed); durable work восстанавливают scanners.
 */
final class DependencyUnavailable extends ExecutionInterrupted
{
    public function __construct(public readonly string $dependency, ?\Throwable $previous = null)
    {
        parent::__construct('Process dependency unavailable: '.$dependency.'.', previous: $previous);
    }
}
