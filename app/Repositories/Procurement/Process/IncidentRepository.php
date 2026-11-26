<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\TransitionData;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Telemetry\LogChannel;
use App\Support\SecretRedactor;

/**
 * Writer prc_process_incidents: один нерешённый incident на fingerprint (partial unique). Повтор
 * того же fingerprint только обновляет last_seen/occurrences; открытие журналируется incident
 * transition с projection intent в той же tx. Evidence — только typed IDs, без payload/secrets.
 */
final readonly class IncidentRepository
{
    public function __construct(private TransitionRepository $transitions) {}

    public function openMembershipIncident(
        WorkId $workId,
        string $scopeKey,
        string $reason,
        ?ProcessUuid $incidentId = null,
        ?ProcessUuid $eventId = null,
        ?ProcessUuid $messageId = null,
    ): void {
        $this->open(
            fingerprint: ContentHash::of('admission-membership:v1:'.$workId->value->value),
            scopeKey: $scopeKey,
            kind: 'admission_membership_mismatch',
            evidence: ['work_id' => $workId->value->value, 'reason' => $reason],
            incidentId: $incidentId ?? ProcessUuid::random(),
            eventId: $eventId ?? ProcessUuid::random(),
            projectionMessageId: $messageId ?? ProcessUuid::random(),
        );
    }

    /** @param array<string, int|string> $evidence */
    public function open(
        ContentHash $fingerprint,
        string $scopeKey,
        string $kind,
        array $evidence,
        ProcessUuid $incidentId,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): ProcessUuid {
        CallerTransaction::require('Incident open');
        $row = LedgerRow::one(
            "INSERT INTO prc_process_incidents
               (id, fingerprint, scope_key, kind, state, first_seen_at, last_seen_at, occurrences, transition_seq, evidence, evidence_schema_version)
             VALUES (?, decode(?, 'hex'), ?, ?, 'open', clock_timestamp(), clock_timestamp(), 1, 1, ?::jsonb, 1)
             ON CONFLICT (fingerprint) WHERE state <> 'resolved'
             DO UPDATE SET last_seen_at = clock_timestamp(), occurrences = prc_process_incidents.occurrences + 1
             RETURNING id, (xmax = 0) AS inserted",
            [$incidentId->value, $fingerprint->hex, $scopeKey, $kind, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        ) ?? throw new \LogicException('Incident upsert returned no row.');
        $id = new ProcessUuid($row->string('id'));
        if ($row->bool('inserted')) {
            $this->transitions->recordIncident(new TransitionData(
                eventId: $eventId,
                projectionMessageId: $projectionMessageId,
                entityId: $id,
                entitySeq: 1,
                previousState: null,
                newState: 'open',
                reasonCode: $kind,
                actor: ActorKind::System,
            ));
            LogChannel::Process->error('process.incident_opened', ['incident_id' => $id->value, 'scope_key' => $scopeKey, 'kind' => $kind, 'evidence' => $evidence]);
        }

        return $id;
    }

    /**
     * AcknowledgeIncident: open → acknowledged под row FOR UPDATE с optimistic transition_seq.
     * Resolved incident не переоткрывается (Terminal), уже acknowledged — Duplicate без второго
     * transition. Acknowledge не закрывает причину: fingerprint остаётся нерешённым до resolve.
     */
    public function acknowledge(
        ProcessUuid $incidentId,
        int $expectedTransitionSeq,
        string $reason,
        int $actorId,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): OperatorCommandStatus {
        CallerTransaction::require('Incident acknowledge');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200 || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Incident acknowledge requires a reason.');
        }
        $reason = SecretRedactor::text($reason);
        $row = LedgerRow::one('SELECT state, transition_seq FROM prc_process_incidents WHERE id = ? FOR UPDATE', [$incidentId->value]);
        if ($row === null) {
            return OperatorCommandStatus::NotFound;
        }
        $state = $row->string('state');
        if ($state === 'resolved') {
            return OperatorCommandStatus::Terminal;
        }
        if ($state === 'acknowledged') {
            return OperatorCommandStatus::Duplicate;
        }
        if ($row->int('transition_seq') !== $expectedTransitionSeq) {
            return OperatorCommandStatus::VersionConflict;
        }
        $changed = LedgerRow::one(
            "UPDATE prc_process_incidents SET state = 'acknowledged', acknowledged_by = ?, transition_seq = transition_seq + 1
             WHERE id = ? RETURNING transition_seq",
            [$actorId, $incidentId->value],
        ) ?? throw new \LogicException('Incident row vanished under its own lock.');
        $this->transitions->recordIncident(new TransitionData(
            eventId: $eventId,
            projectionMessageId: $projectionMessageId,
            entityId: $incidentId,
            entitySeq: $changed->int('transition_seq'),
            previousState: 'open',
            newState: 'acknowledged',
            reasonCode: 'manual_acknowledge',
            actor: ActorKind::Operator,
            actorId: $actorId,
            details: ['reason' => $reason],
        ));

        return OperatorCommandStatus::Applied;
    }
}
