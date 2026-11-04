<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

/**
 * Один маршрут доставки stage: connection, queue и вся цепочка таймаутов. Цепочка
 * slice < jobTimeout < horizonTimeout < retryAfter < lease проверяется StageQueueMap при сборке;
 * здесь только неизменяемое значение.
 */
final readonly class QueueRoute
{
    public function __construct(
        public StageCode $stage,
        public string $connection,
        public string $queue,
        public int $sliceSeconds,
        public int $jobTimeout,
        public int $horizonTimeout,
        public int $retryAfter,
        public int $leaseSeconds,
    ) {
        ProcessValue::text($connection);
        ProcessValue::text($queue);
        if (! ($sliceSeconds < $jobTimeout && $jobTimeout < $horizonTimeout && $horizonTimeout < $retryAfter && $retryAfter < $leaseSeconds)) {
            throw new \InvalidArgumentException('Queue timing must satisfy slice < job < horizon < retry_after < lease.');
        }
    }
}
