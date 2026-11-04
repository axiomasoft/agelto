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
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Str;

/**
 * Hard cancel одного nonterminal work: короткая full-prefix tx с optimistic
 * transition_seq. Увеличивает generation, поэтому поздняя публикация владельца невозможна, и
 * освобождает всю admission group. Отмена run (CancelRun) shared work других runs не затрагивает.
 * Caller проверяет RBAC и передаёт ожидаемую transition_seq для защиты от устаревшей команды.
 */
final readonly class CancelWork
{
    private const int MAX_RELOADS = 3;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkMutationRepository $mutations,
        private RecoveryReconciler $recovery,
        private ProcessTransaction $transaction,
    ) {}

    public function execute(WorkId $workId, string $reason, ?int $expectedTransitionSeq = null, ?int $actorId = null): WorkMutationResult
    {
        $ids = [];
        foreach (['event', 'projection', 'feed'] as $name) {
            $ids[$name] = new ProcessUuid(Str::lower((string) Str::uuid()));
        }
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readRecoverySnapshot($workId, true);
            if ($snapshot instanceof AuthorityGap) {
                return WorkMutationResult::refused($snapshot === AuthorityGap::WorkMissing ? WorkMutationStatus::NotFound : WorkMutationStatus::SnapshotChanged);
            }
            $result = $this->transaction->run(fn (): WorkMutationResult|PrefixFailure => $this->mutations->cancelWork(
                $snapshot, $reason, $expectedTransitionSeq, $actorId, $this->recovery->providerResolver(), $ids,
            ));
            if ($result instanceof WorkMutationResult) {
                return $result;
            }
        }

        return WorkMutationResult::refused(WorkMutationStatus::SnapshotChanged);
    }
}
