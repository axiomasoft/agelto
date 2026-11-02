<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\HandlerFaultEvidence;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Единственная точка исполнения одной queue delivery (bp-jobs, execution#s02): envelope → claim →
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

        // Context attempt живёт ровно на время работы и восстанавливается в finally (observability#s02).
        return $this->correlation->scope($lease, fn (): DeliveryOutcome => $this->execute($lease));
    }

    private function execute(ExecutionLease $lease): DeliveryOutcome
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            // Claim только что видел тот же snapshot: расхождение — дефект, work восстановит recovery.
            throw new \LogicException('Claimed work has no readable authority snapshot.');
        }
        $context = $this->contexts->open($lease);
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
        return $this->finalize(fn () => $this->finalizer->finishHandlerFault($context->lease(), HandlerFaultEvidence::fromThrowable($fault)));
    }

    /** @param \Closure(): mixed $finalization */
    private function finalize(\Closure $finalization): DeliveryOutcome
    {
        try {
            $finalization();
        } catch (DependencyUnavailable $unavailable) {
            ProcessLog::dependencyUnavailable($unavailable->dependency, $unavailable);

            return DeliveryOutcome::DependencyUnavailable;
        }

        return DeliveryOutcome::Executed;
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
        return new RetryAt($this->clock->dbNow()->value->add(new \DateInterval('PT'.self::RETRY_SECONDS.'S')));
    }

    /** Неподдерживаемая queue schema: видимый incident; саму работу восстановят durable scanners. */
    private function quarantine(DeliveryEnvelope $delivery): void
    {
        ProcessLog::quarantined('queue_schema_unsupported', [
            'work_id' => $delivery->workId->value->value, 'queue_schema_version' => $delivery->queueSchemaVersion,
        ]);
        $ids = [self::uuid(), self::uuid(), self::uuid()];
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

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
