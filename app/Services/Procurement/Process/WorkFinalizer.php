<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CompletionResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\HandlerFaultEvidence;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\WorkFinalizationRepository;
use Illuminate\Support\Str;

/**
 * Единственная точка guarded завершения attempt (execution#s03, bp-sql-finalize). finish
 * исчерпывающе dispatch-ит пять closed StageOutcome: success в complete, остальные четыре в
 * settle; неизвестный вариант — LogicException, не success. finishHandlerFault — entrypoint того же
 * full-prefix finalizer для unexpected programming Throwable: без новой StageOutcome variant,
 * nested tx или logger mutation. До tx — unlocked AuthoritySnapshot и стабильные event/message
 * IDs; ProcessTransaction повторяет только SQL. Изменившийся snapshot — rollback и reload.
 */
final readonly class WorkFinalizer
{
    /** Snapshot менялся на каждой попытке: work остаётся durable running до recovery. */
    private const int MAX_RELOADS = 3;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkFinalizationRepository $finalization,
        private IncidentRepository $incidents,
        private ProcessTransaction $transaction,
    ) {}

    public function finish(ExecutionLease $lease, StageOutcome $outcome): CompletionResult
    {
        return match (true) {
            $outcome instanceof StageSucceeded => $this->complete($lease, $outcome->result),
            $outcome instanceof StagePartial, $outcome instanceof StageDeferred,
            $outcome instanceof StageBlocked, $outcome instanceof StageFailedItem => $this->settle($lease, $outcome),
            default => throw new \LogicException('Unknown StageOutcome variant.'),
        };
    }

    public function complete(ExecutionLease $lease, ValidatedStageResult $result): CompletionResult
    {
        return $this->apply($lease, new StageSucceeded($result));
    }

    public function settle(ExecutionLease $lease, StagePartial|StageDeferred|StageBlocked|StageFailedItem $outcome): CompletionResult
    {
        return $this->apply($lease, $outcome);
    }

    /**
     * Locked per-work/version budget (execution#handler-fault-budget): prior finished faults этой
     * work и executing handler version + текущий; ниже limit — deferred(handler_fault, +60 s), на
     * limit — blocked(handler_fault_limit) с exhaustion incident. Item budget не тратится; rollback
     * не расходует budget. Global distinct-input detector работает отдельно после commit.
     */
    public function finishHandlerFault(ExecutionLease $lease, HandlerFaultEvidence $evidence): CompletionResult
    {
        return $this->apply($lease, $evidence);
    }

    private function apply(ExecutionLease $lease, StageOutcome|HandlerFaultEvidence $outcome): CompletionResult
    {
        $ids = [];
        foreach (['event', 'projection', 'feed', 'incident', 'incident_event', 'incident_projection', 'membership', 'membership_event', 'membership_projection'] as $name) {
            $ids[$name] = self::uuid();
        }
        /** @var array<int, array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid}> $pool */
        $pool = [];
        // Successor IDs выдаются по индексу один раз: повтор той же tx переиспользует их.
        /** @var \Closure(int): array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid} $successorIds */
        $successorIds = static function (int $index) use (&$pool): array {
            return $pool[$index] ??= ['work' => self::uuid(), 'event' => self::uuid(), 'projection' => self::uuid()];
        };
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
            if ($snapshot instanceof AuthorityGap) {
                return CompletionResult::invariantViolation();
            }
            // By-reference флаг: arrow fn захватывает по значению и потеряла бы признак corrupt membership.
            $corrupt = false;
            $result = $this->transaction->run(
                function () use ($snapshot, $lease, $outcome, $ids, $successorIds, &$corrupt): CompletionResult|PrefixFailure {
                    $corrupt = false;

                    return $this->finalization->finishLocked($snapshot, $lease, $outcome, $ids, $successorIds, $corrupt);
                },
            );
            if ($result instanceof CompletionResult) {
                if ($corrupt && $result->status === CompletionStatus::InvariantViolation) {
                    $this->openMembershipIncident($snapshot, $lease, $ids);
                }

                return $result;
            }
        }

        throw new DependencyUnavailable('process_authority');
    }

    /** @param array<string, ProcessUuid> $ids */
    private function openMembershipIncident(AuthoritySnapshot $snapshot, ExecutionLease $lease, array $ids): void
    {
        $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
            fingerprint: ContentHash::of('admission-membership:v1:'.$snapshot->workId->value->value),
            scopeKey: 'work:'.$snapshot->workId->value->value,
            kind: 'admission_membership_mismatch',
            evidence: ['work_id' => $snapshot->workId->value->value, 'generation' => $lease->generation],
            incidentId: $ids['membership'],
            eventId: $ids['membership_event'],
            projectionMessageId: $ids['membership_projection'],
        ));
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
