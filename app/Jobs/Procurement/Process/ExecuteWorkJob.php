<?php

declare(strict_types=1);

namespace App\Jobs\Procurement\Process;

use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\Process\ProcessLog;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use PDOException;
use Throwable;

/**
 * Единственная queue delivery Process: тонкий конверт work_id, dispatch_generation,
 * message_id и queue_schema_version. Ни моделей, ни credentials, ни payload. Job — только доставка:
 * бизнес-retry, ожидание и восстановление принадлежат durable ledger (Redis не authority), поэтому
 * одна попытка, без release/backoff/delay, ShouldBeUnique, WithoutOverlapping, RateLimited и
 * Bus chain/batch. tries и maxExceptions заданы атрибутами Laravel (payload maxTries=1,
 * maxExceptions=1): свойства очередного ретрая в Jobs/Procurement запрещает JobRetryContractGuardTest.
 *
 * Public `$timeout` назначает relay из StageQueueMap до push — Laravel читает свойство и кладёт его
 * в payload, метод timeout() не используется. Transient DB failure ACK-ится (durable scanners
 * восстановят); constraint/schema/programming SQL errors видимо пробрасываются.
 */
#[Tries(1)]
#[MaxExceptions(1)]
final class ExecuteWorkJob implements ShouldQueue
{
    use Queueable;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = false;

    /** Назначается relay из StageQueueMap до push. */
    public int $timeout;

    public function __construct(
        public readonly string $workId,
        public readonly int $dispatchGeneration,
        public readonly string $messageId,
        public readonly int $queueSchemaVersion = DeliveryEnvelope::SCHEMA_VERSION,
    ) {}

    public function handle(WorkRunner $runner): void
    {
        try {
            $runner->runDelivery(DeliveryEnvelope::fromScalars($this->workId, $this->dispatchGeneration, $this->messageId, $this->queueSchemaVersion));
        } catch (QueryException|PDOException $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)) {
                throw $failure;
            }
            // Sanitized и non-throwing: ACK, work остаётся durable и его подберут scanners.
            ProcessLog::dependencyUnavailable('database', $failure);
        }
    }

    public function failed(?Throwable $failure): void
    {
        // Только telemetry: failed() не мутирует ledger и после kill процесса может не вызваться.
        ProcessLog::jobFailed($this->workId, $this->dispatchGeneration, $failure);
    }
}
