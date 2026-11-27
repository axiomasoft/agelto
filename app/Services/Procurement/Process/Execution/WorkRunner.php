<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Execution\CompletionResult;
use App\Dto\Procurement\Process\Execution\DeliveryEnvelope;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\HandlerFaultEvidence;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Outcomes\ClaimStatus;
use App\Enums\Procurement\Process\Outcomes\CompletionStatus;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Enums\Procurement\Process\Outcomes\DeliveryOutcome;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Infrastructure\DatabaseFailureClassifier;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Database\QueryException;

/**
 * Единственная точка исполнения одной queue delivery: envelope → claim →
 * только Claimed вызывает DI handler → единственный WorkFinalizer. Runner не выбирает handler по
 * stage и не знает платформ: handler приходит из StageRegistry. Управляющие выходы различаются до
 * generic Throwable: LeaseLost прекращает работу без публикации, SliceExhausted сохраняет только
 * уже устойчивый checkpoint (StagePartial) либо deferred(slice_exhausted), typed dependency
 * становится deferred соответствующего reason. Только неожиданный programming Throwable идёт в
 * finishHandlerFault (locked per-work budget). Сбой finalizer никогда не запускает handler или
 * send второй раз в этой delivery: work остаётся durable running до recovery.
 */
final readonly class WorkRunner
{
    /** Typed зависимость → deferred reason; имя вне таблицы — дефект кода, а не ожидание. */
    private const array DEPENDENCY_REASONS = [
        'storage' => DeferralReason::StorageUnavailable,
        'storage_capacity' => DeferralReason::CapacityWait,
        'converter' => DeferralReason::ConverterUnavailable,
        'provider' => DeferralReason::ProviderUnavailable,
    ];

    /** Минимальная пауза до повтора после typed dependency / slice без устойчивого checkpoint. */
    private const int RETRY_SECONDS = 30;

    private const int RETRY_JITTER_SECONDS = 5;

    public function __construct(
        private WorkStoreRepository $store,
        private AuthorityRepository $authority,
        private StageRegistry $registry,
        private ExecutionContextFactory $contexts,
        private WorkFinalizer $finalizer,
        private IncidentRepository $incidents,
        private ProcessTransaction $transaction,
        private ProcessClock $clock,
        private ExecutionCorrelation $correlation,
    ) {}

    public function runDelivery(DeliveryEnvelope $delivery): DeliveryOutcome
    {
        if (! $delivery->supported()) {
            $this->quarantine($delivery);

            return DeliveryOutcome::Quarantined;
        }
        $claim = $this->store->claim($delivery->workId, $delivery->dispatchGeneration);
        if ($claim->status !== ClaimStatus::Claimed || $claim->lease === null) {
            ProcessLog::deliveryRejected($delivery->workId->value->value, $delivery->dispatchGeneration->value, $claim->status);

            return DeliveryOutcome::Rejected;
        }

        $lease = $claim->lease;

        // Context attempt живёт ровно на время работы и восстанавливается в finally.
        return $this->correlation->scope($lease, fn (): DeliveryOutcome => $this->execute($lease));
    }

    private function execute(ExecutionLease $lease): DeliveryOutcome
    {
        try {
            $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
            if (! $snapshot instanceof AuthoritySnapshot) {
                // Claim только что видел тот же snapshot: расхождение — дефект, work восстановит recovery.
                throw new \LogicException('Claimed work has no readable authority snapshot.');
            }
            $context = $this->contexts->open($lease);
        } catch (DependencyUnavailable $unavailable) {
            ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);

            return DeliveryOutcome::DependencyUnavailable;
        } catch (\PDOException $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)) {
                throw $failure;
            }
            ProcessLog::dependencyUnavailable('database', $failure);

            return DeliveryOutcome::DependencyUnavailable;
        }

        try {
            $outcome = $this->registry->handler($lease->stage)->execute($snapshot->input, $context);
        } catch (LeaseLost) {
            return DeliveryOutcome::LeaseLost;
        } catch (SliceExhausted) {
            $outcome = $this->sliceOutcome($context);
        } catch (DependencyUnavailable $unavailable) {
            $reason = self::DEPENDENCY_REASONS[$unavailable->dependency] ?? null;
            if ($unavailable->dependency === 'database') {
                // DB недоступна: новых effects нет, finalize тоже невозможен; durable running → recovery.
                ProcessLog::dependencyUnavailable('database', $unavailable);

                return DeliveryOutcome::DependencyUnavailable;
            }
            if ($reason === null) {
                return $this->finishFault($context, $unavailable);
            }
            $outcome = new StageDeferred($reason, $this->retryAt());
        } catch (\PDOException $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)) {
                return $this->finishFault($context, $failure);
            }
            ProcessLog::dependencyUnavailable('database', $failure);

            return DeliveryOutcome::DependencyUnavailable;
        } catch (\Throwable $fault) {
            return $this->finishFault($context, $fault);
        }

        return $this->finalize(fn () => $this->finalizer->finish($context->lease(), $outcome));
    }

    private function finishFault(ExecutionContext $context, \Throwable $fault): DeliveryOutcome
    {
        $lease = $context->lease();
        $evidence = HandlerFaultEvidence::fromThrowable($fault);
        ProcessLog::handlerFault($lease->workId->value->value, $lease->stage->value, $lease->generation, $evidence->signature->hex, $fault);

        return $this->finalize(fn () => $this->finalizer->finishHandlerFault($lease, $evidence));
    }

    /** @param \Closure(): CompletionResult $finalization */
    private function finalize(\Closure $finalization): DeliveryOutcome
    {
        try {
            $result = $finalization();
        } catch (DependencyUnavailable $unavailable) {
            ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);

            return DeliveryOutcome::DependencyUnavailable;
        } catch (\PDOException $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)) {
                throw $failure;
            }
            ProcessLog::dependencyUnavailable('database', $failure);

            return DeliveryOutcome::DependencyUnavailable;
        }

        if ($result->status === CompletionStatus::InvariantViolation) {
            ProcessLog::deliveryWarning('work_finalization_invariant_violation', ['status' => $result->status->value]);
        }

        return match ($result->status) {
            CompletionStatus::Applied => DeliveryOutcome::Executed,
            CompletionStatus::LostOwnership => DeliveryOutcome::LeaseLost,
            CompletionStatus::InvariantViolation => DeliveryOutcome::Rejected,
        };
    }

    /** Устойчивый прогресс сохраняется как Partial (ready); без него — deferred(slice_exhausted). */
    private function sliceOutcome(ExecutionContext $context): StageOutcome
    {
        $checkpoint = $context->lease()->checkpoint;
        if ($checkpoint !== null) {
            return new StagePartial($checkpoint->stageProgress, new MissingParts(['remaining_work']));
        }

        return new StageDeferred(DeferralReason::SliceExhausted, $this->retryAt());
    }

    private function retryAt(): RetryAt
    {
        $seconds = self::RETRY_SECONDS + random_int(0, self::RETRY_JITTER_SECONDS);

        return new RetryAt($this->clock->dbNow()->value->add(new \DateInterval('PT'.$seconds.'S')));
    }

    /** Неподдерживаемая queue schema: видимый incident; саму работу восстановят durable scanners. */
    private function quarantine(DeliveryEnvelope $delivery): void
    {
        ProcessLog::quarantined('queue_schema_unsupported', [
            'work_id' => $delivery->workId->value->value, 'queue_schema_version' => $delivery->queueSchemaVersion,
        ]);
        $ids = [ProcessUuid::random(), ProcessUuid::random(), ProcessUuid::random()];
        try {
            $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
                fingerprint: ContentHash::of('queue-schema:v1:'.$delivery->queueSchemaVersion),
                scopeKey: 'queue',
                kind: 'queue_schema_unsupported',
                evidence: ['queue_schema_version' => $delivery->queueSchemaVersion, 'supported_version' => DeliveryEnvelope::SCHEMA_VERSION],
                incidentId: $ids[0],
                eventId: $ids[1],
                projectionMessageId: $ids[2],
            ));
        } catch (QueryException $exception) {
            if (! DatabaseFailureClassifier::isTransient($exception)) {
                throw $exception;
            }
            ProcessLog::dependencyUnavailable('database', $exception);
        }
    }
}
