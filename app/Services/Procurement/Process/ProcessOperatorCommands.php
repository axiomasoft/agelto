<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Actions\Procurement\Process\AcknowledgeIncident;
use App\Actions\Procurement\Process\CancelRun;
use App\Actions\Procurement\Process\CancelWork;
use App\Actions\Procurement\Process\RetryFailedItem;
use App\Actions\Procurement\Process\RetryHandlerFaultedWork;
use App\Actions\Procurement\Process\SetScopeMode;
use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkMutationResult;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Единственная точка входа Filament/CLI в typed operator commands (observability#s04): RBAC по
 * ProcessControlPermission, обязательный reason, actor из User. Все записи идут через Actions
 * ledger-слоя с optimistic version; прямых UPDATE из UI нет (ProcessOperatorBoundaryTest).
 * Automatic scheduler эти методы не вызывает.
 */
final readonly class ProcessOperatorCommands
{
    public function __construct(
        private SetScopeMode $scopeMode,
        private CancelWork $cancelWork,
        private CancelRun $cancelRun,
        private RetryHandlerFaultedWork $retryFaulted,
        private RetryFailedItem $retryFailedItem,
        private AcknowledgeIncident $acknowledgeIncident,
    ) {}

    public function pauseScope(User $actor, string $scopeKey, int $expectedVersion, string $reason): OperatorCommandResult
    {
        return $this->setScope($actor, ProcessControlPermission::PauseScope, ControlMode::Paused, $scopeKey, $expectedVersion, $reason);
    }

    public function resumeScope(User $actor, string $scopeKey, int $expectedVersion, string $reason): OperatorCommandResult
    {
        return $this->setScope($actor, ProcessControlPermission::ResumeScope, ControlMode::Enabled, $scopeKey, $expectedVersion, $reason);
    }

    public function cancelWork(User $actor, WorkId $workId, int $expectedTransitionSeq, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, ProcessControlPermission::CancelWork, $reason);

        return self::fromWork($this->cancelWork->execute($workId, $reason, $expectedTransitionSeq, $actor->id));
    }

    public function cancelRun(User $actor, ProcessUuid $runId, int $expectedTransitionSeq, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, ProcessControlPermission::CancelRun, $reason);

        return self::fromWork($this->cancelRun->execute($runId, $reason, $expectedTransitionSeq, $actor->id));
    }

    /** EXR-02: новый work со свежим budget; ResumeScope blocked(handler_fault_limit) не снимает. */
    public function retryHandlerFaultedWork(User $actor, WorkId $workId, int $expectedTransitionSeq, ProcessUuid $commandId, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, ProcessControlPermission::RetryHandlerFault, $reason);

        return self::fromWork($this->retryFaulted->execute($workId, $expectedTransitionSeq, $commandId, $reason, $actor->id));
    }

    public function retryFailedItem(User $actor, WorkId $workId, int $expectedTransitionSeq, ProcessUuid $commandId, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, ProcessControlPermission::RetryFailedItem, $reason);

        return self::fromWork($this->retryFailedItem->execute($workId, $expectedTransitionSeq, $commandId, $reason, $actor->id));
    }

    public function acknowledgeIncident(User $actor, ProcessUuid $incidentId, int $expectedTransitionSeq, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, ProcessControlPermission::AcknowledgeIncident, $reason);

        return new OperatorCommandResult($this->acknowledgeIncident->execute($incidentId, $expectedTransitionSeq, $reason, $actor->id));
    }

    private function setScope(User $actor, ProcessControlPermission $permission, ControlMode $mode, string $scopeKey, int $expectedVersion, string $reason): OperatorCommandResult
    {
        $this->authorize($actor, $permission, $reason);
        $result = $this->scopeMode->execute($scopeKey, $mode, $expectedVersion, $reason, $actor->id);

        return new OperatorCommandResult(OperatorCommandStatus::fromControl($result->status), $result->controlVersion);
    }

    private function authorize(User $actor, ProcessControlPermission $permission, string $reason): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException('Process command is not permitted: '.$permission->value);
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Operator command requires a reason.');
        }
    }

    private static function fromWork(WorkMutationResult $result): OperatorCommandResult
    {
        return new OperatorCommandResult(OperatorCommandStatus::fromWork($result->status), null, $result->successor);
    }
}
