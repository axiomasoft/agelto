<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Process;

use App\Dto\Procurement\Process\ControlChangeResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\ControlMode;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Str;

/**
 * PauseScope / ResumeScope (observability#s04): manual mode одной control scope с optimistic
 * control_version. Resume снимает только manual pause этой row — health/handler holds и
 * blocked(handler_fault_limit) не трогает. Повтор уже применённого режима — Unchanged, не новая
 * версия. Actor/reason/before/after попадают в transition тем же commit.
 */
final readonly class SetScopeMode
{
    public function __construct(private ControlRepository $controls, private ProcessTransaction $transaction) {}

    public function execute(string $scopeKey, ControlMode $mode, int $expectedVersion, string $reason, ?int $actorId = null): ControlChangeResult
    {
        $eventId = new ProcessUuid(Str::lower((string) Str::uuid()));
        $projectionId = new ProcessUuid(Str::lower((string) Str::uuid()));

        return $this->transaction->run(fn (): ControlChangeResult => $this->controls->setMode(
            $scopeKey, $mode, $actorId, $reason, $expectedVersion, $eventId, $projectionId,
        ));
    }
}
