<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkMutationResult;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\WorkMutationRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Str;

/**
 * RetryHandlerFaultedWork(workId, expectedTransitionSeq, commandId, reason) — ручной выход из
 * blocked(handler_fault_limit): исчерпанный work → superseded,
 * новый explicit manual intent/work key со свежим budget, history и evidence сохранены. Повтор
 * той же команды (commandId) — Duplicate без второго intent; конкурирующая команда со старым
 * expectedTransitionSeq — VersionConflict. Generic ResumeScope/ValidateHandler/смена версии этот
 * work не освобождают, автоматический scheduler эту мутацию не вызывает. RBAC проверяет caller.
 */
final readonly class RetryHandlerFaultedWork
{
    private const int MAX_RELOADS = 3;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkMutationRepository $mutations,
        private ProcessTransaction $transaction,
    ) {}

    public function execute(WorkId $workId, int $expectedTransitionSeq, ProcessUuid $commandId, string $reason, ?int $actorId = null): WorkMutationResult
    {
        $ids = [];
        foreach (['intent', 'work', 'work_event', 'work_projection', 'event', 'projection', 'feed'] as $name) {
            $ids[$name] = new ProcessUuid(Str::lower((string) Str::uuid()));
        }
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readRecoverySnapshot($workId, true);
            if ($snapshot instanceof AuthorityGap) {
                return WorkMutationResult::refused($snapshot === AuthorityGap::WorkMissing ? WorkMutationStatus::NotFound : WorkMutationStatus::SnapshotChanged);
            }
            $result = $this->transaction->run(fn (): WorkMutationResult|PrefixFailure => $this->mutations->retryFaulted(
                $snapshot, $expectedTransitionSeq, $commandId, $reason, $actorId, $ids,
            ));
            if ($result instanceof WorkMutationResult) {
                return $result;
            }
        }

        return WorkMutationResult::refused(WorkMutationStatus::SnapshotChanged);
    }
}
