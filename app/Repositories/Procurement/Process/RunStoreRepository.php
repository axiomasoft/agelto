<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\RunKind;
use Illuminate\Support\Facades\DB;

/**
 * Writer prc_process_runs. Run начинается в discovery collecting (transition_seq 1) вместе со
 * своим transition и projection intent; closure complete/incomplete и CancelRun — отдельные
 * fenced lifecycle mutations под lock строки run.
 */
final readonly class RunStoreRepository
{
    public function __construct(private TransitionRepository $transitions) {}

    public function storeRun(
        ProcessUuid $id,
        RunKind $kind,
        ?string $triggerKey,
        ?int $profileId,
        VersionedPayload $configuration,
        ?int $requestedBy,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): void {
        CallerTransaction::require('Process run insert');
        if ($configuration->json === null) {
            throw new \InvalidArgumentException('Run configuration snapshot is required.');
        }
        DB::insert(
            "INSERT INTO prc_process_runs
               (id, kind, trigger_key, profile_id, configuration_snapshot, configuration_schema_version, configuration_hash,
                requested_by, requested_at, discovery_outcome, transition_seq)
             VALUES (?, ?, ?, ?, ?::jsonb, ?, decode(?, 'hex'), ?, clock_timestamp(), 'collecting', 1)",
            [
                $id->value, $kind->value, $triggerKey, $profileId, $configuration->json, $configuration->schemaVersion,
                ContentHash::of($configuration->json)->hex, $requestedBy,
            ],
        );
        $this->transitions->recordRun($eventId, $projectionMessageId, $id, 1, null, 'collecting', null, ActorKind::System);
    }
}
