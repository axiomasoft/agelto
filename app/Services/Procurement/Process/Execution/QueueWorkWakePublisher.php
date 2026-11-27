<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Execution\WorkWake;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Procurement\Process\Contracts\WorkWakePublisher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Production publisher: маленький ExecuteWorkJob с public timeout из StageQueueMap, pushOn на
 * connection+queue той же карты. Connection никогда не hardcode-ится: default redis остаётся
 * для notifications/mail, а Process delivery идёт только своими connections.
 */
final readonly class QueueWorkWakePublisher implements WorkWakePublisher
{
    public function __construct(private StageQueueMap $routes, private QueueFactory $queues) {}

    public function publish(WorkWake $wake): void
    {
        $route = $this->routes->forStage($wake->stage);
        $job = new ExecuteWorkJob(
            workId: $wake->workId->value->value,
            dispatchGeneration: $wake->dispatchGeneration->value,
            messageId: $wake->messageId->value,
            queueSchemaVersion: $wake->queueSchemaVersion,
        );
        $job->timeout = $route->jobTimeout;
        $this->queues->connection($route->connection)->pushOn($route->queue, $job);
    }
}
