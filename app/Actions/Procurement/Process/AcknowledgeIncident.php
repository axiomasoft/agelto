<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Process;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\OperatorCommandStatus;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Str;

/** AcknowledgeIncident: короткая tx, actor/reason в audit transition. */
final readonly class AcknowledgeIncident
{
    public function __construct(private IncidentRepository $incidents, private ProcessTransaction $transaction) {}

    public function execute(ProcessUuid $incidentId, int $expectedTransitionSeq, string $reason, int $actorId): OperatorCommandStatus
    {
        $eventId = new ProcessUuid(Str::lower((string) Str::uuid()));
        $projectionId = new ProcessUuid(Str::lower((string) Str::uuid()));

        return $this->transaction->run(fn (): OperatorCommandStatus => $this->incidents->acknowledge(
            $incidentId, $expectedTransitionSeq, $reason, $actorId, $eventId, $projectionId,
        ));
    }
}
