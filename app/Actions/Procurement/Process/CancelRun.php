<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkMutationResult;
use App\Repositories\Procurement\Process\WorkMutationRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Str;

/**
 * CancelRun (execution#s08): отмена намерения run, а не слепая отмена его work. Run row FOR UPDATE
 * с expected transition_seq; collecting → incomplete(cancelled), cancel_requested_at и audit event
 * атомарны. Shared work, нужное другим runs, завершается и сохраняет provenance; hard cancel
 * конкретного exclusive work — явный CancelWork.
 */
final readonly class CancelRun
{
    public function __construct(private WorkMutationRepository $mutations, private ProcessTransaction $transaction) {}

    public function execute(ProcessUuid $runId, string $reason, ?int $expectedTransitionSeq = null, ?int $actorId = null): WorkMutationResult
    {
        $ids = [
            'event' => new ProcessUuid(Str::lower((string) Str::uuid())),
            'projection' => new ProcessUuid(Str::lower((string) Str::uuid())),
        ];

        return $this->transaction->run(fn (): WorkMutationResult => $this->mutations->cancelRun($runId, $reason, $expectedTransitionSeq, $actorId, $ids));
    }
}
