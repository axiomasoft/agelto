<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use RuntimeException;

/**
 * Аренда прогона перехвачена другим воркером. Это не отказ блока и не ошибка прогона:
 * прогон продолжает новый владелец, текущий процесс просто прекращает работу с ним.
 */
final class ExecutionLeaseLost extends RuntimeException
{
    public static function notOwned(): self
    {
        return new self('Execution lease is not owned by this processor.');
    }

    public static function noLongerOwned(): self
    {
        return new self('Execution lease is no longer owned by this processor.');
    }
}
