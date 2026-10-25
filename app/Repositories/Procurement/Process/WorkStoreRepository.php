<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\SchemaSupportedStages;
use Illuminate\Support\Facades\DB;

/**
 * Writer intents и новых ready work (P1.6 baseline). Work ссылается на реальный subject одной
 * owner column своей стадии; work_key = WorkIdentity, input immutable (новое input — новый
 * intent/work). Claim/heartbeat/checkpoint/finalize добавляют следующие пункты с полным prefix.
 */
final readonly class WorkStoreRepository
{
    public function __construct(
        private ProcessPayloadCodec $codec,
        private TransitionRepository $transitions,
    ) {}

    public function storeIntent(
        ProcessUuid $id,
        ContentHash $intentKey,
        StageCode $stage,
        SubjectKey $subject,
        ContentHash $semanticPolicyHash,
        ContentHash $accessPolicyHash,
        int $cycleSeq,
        int $expectedHeadGeneration,
        IntentState $state,
        ?\DateTimeImmutable $nextDueAt = null,
    ): void {
        CallerTransaction::require('Operation intent insert');
        $ownerSubject = SchemaSupportedStages::subject($stage);
        if ($ownerSubject !== $subject->kind) {
            throw new \InvalidArgumentException('Intent subject does not belong to the stage owner table.');
        }
        DB::insert(
            "INSERT INTO prc_operation_intents
               (id, intent_key, stage, subject_kind, subject_id, semantic_policy_hash, access_policy_hash,
                cycle_seq, expected_head_generation, state, requested_at, next_due_at)
             VALUES (?, decode(?, 'hex'), ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), ?, ?, ?, clock_timestamp(), ?)",
            [
                $id->value, $intentKey->hex, $stage->value, $subject->kind->value,
                is_int($subject->id) ? (string) $subject->id : $subject->id->value,
                $semanticPolicyHash->hex, $accessPolicyHash->hex, $cycleSeq, $expectedHeadGeneration, $state->value,
                $nextDueAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    /** Новый work всегда ready без due_at; transition 1 и projection intent в той же tx. */
    public function storeWork(
        WorkId $id,
        WorkInput $input,
        int $inputRevision,
        ?ProcessUuid $intentId,
        string $cohortKey,
        ?int $platformId,
        ?ProcessUuid $originRunId,
        int $priority,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): void {
        CallerTransaction::require('Work insert');
        $stage = $input->stage();
        $ownerColumn = SchemaSupportedStages::ownerColumn($stage);
        $payload = $this->codec->encodeInput($input);
        if ($payload->json === null || $payload->schemaVersion === null) {
            throw new \LogicException('Work input codec returned an absent payload.');
        }
        /** @var array<string, int|string> $fields */
        $fields = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
        DB::insert(
            "INSERT INTO prc_process_work
               (id, stage, work_key, work_identity, work_identity_schema_version, intent_id, input_revision, input_hash,
                input_payload, input_schema_version, state, priority, platform_id, cohort_key, origin_run_id,
                {$ownerColumn}, transition_seq, created_at, updated_at)
             VALUES (?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, ?, ?, ?, ?, 1,
                     clock_timestamp(), clock_timestamp())",
            [
                $id->value->value, $stage->value, (new WorkIdentity($input))->key()->hex, $payload->json, $payload->schemaVersion,
                $intentId?->value, $inputRevision, self::inputHash($payload->json)->hex, $payload->json, $payload->schemaVersion,
                WorkState::Ready->value, $priority, $platformId, $cohortKey, $originRunId?->value, $fields[$ownerColumn],
            ],
        );
        $this->transitions->recordWork($eventId, $projectionMessageId, $id->value, 1, null, WorkState::Ready->value, null, ActorKind::System, 0);
    }

    public function linkRun(ProcessUuid $runId, WorkId $workId, RunWorkRelation $relation, AccountingRole $role): void
    {
        CallerTransaction::require('Run work link insert');
        DB::insert(
            'INSERT INTO prc_process_run_work (run_id, work_id, relation, accounting_role) VALUES (?, ?, ?, ?)',
            [$runId->value, $workId->value->value, $relation->value, $role->value],
        );
    }

    /** Immutable input fingerprint: тот же canonical payload, отдельный domain separator от work_key. */
    public static function inputHash(string $canonicalInput): ContentHash
    {
        return ContentHash::of('work-input:v1:'.$canonicalInput);
    }
}
