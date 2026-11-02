<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AdmissionGroupSnapshot;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CapacityAuthority;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectAuthoritySnapshot;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\SubjectLockSnapshot;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CapacityKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;

/**
 * Authority pre-read и полный lock prefix (execution#s05–s07, bp-lock-modes). Pre-read без locks
 * собирает AuthoritySnapshot: control closure, executing handler authority, все required lanes,
 * subject rows и active admission group. lockPrefix берёт tiers строго по порядку: control scopes
 * FOR SHARE (sorted scope_key) → handler authority FOR SHARE → весь capacity set FOR UPDATE
 * (sorted id) → subjects FOR UPDATE (sorted table, PK) → work FOR UPDATE [SKIP LOCKED]. Более ранний
 * tier после work не берётся: изменившийся состав — rollback и reload, а не поздний lock.
 */
final readonly class AuthorityRepository
{
    public function __construct(
        private ProcessPayloadCodec $codec,
        private ProcessPolicy $policy,
        private StageRegistry $registry,
        private SubjectLockResolver $resolver,
    ) {}

    /**
     * Handler version, чью eligibility authority держит recovery: executing version последнего
     * attempt (то, что реально выполнялось) либо, если attempt не было или нужна текущая версия
     * кода, версия registry. Процесс без registry binding восстанавливает по версии attempt.
     */
    public function recoveryHandlerVersion(WorkId $workId, StageCode $stage, bool $preferAttempt): ?string
    {
        $attempt = LedgerRow::one(
            'SELECT handler_version FROM prc_process_attempts WHERE work_id = ? ORDER BY generation DESC LIMIT 1',
            [$workId->value->value],
        )?->string('handler_version');
        $registered = $this->registry->handlerVersion($stage);

        return $preferAttempt ? ($attempt ?? $registered) : ($registered ?? $attempt);
    }

    /**
     * Pre-read для recovery и управляющих мутаций: handler version выбирается по recoveryHandlerVersion,
     * поэтому процесс без handler registration (reconcile daemon, оператор) тоже видит authority.
     */
    public function readRecoverySnapshot(WorkId $workId, bool $executing): AuthoritySnapshot|AuthorityGap
    {
        $row = LedgerRow::one('SELECT stage FROM prc_process_work WHERE id = ?', [$workId->value->value]);
        if ($row === null) {
            return AuthorityGap::WorkMissing;
        }

        return $this->readAuthoritySnapshot($workId, $this->recoveryHandlerVersion($workId, StageCode::from($row->string('stage')), $executing));
    }

    /**
     * Unlocked pre-read; никогда не grant. Каждый факт повторно проверяется под locks.
     * $executingVersion — явная handler version (recovery); по умолчанию версия registry.
     */
    public function readAuthoritySnapshot(WorkId $workId, ?string $executingVersion = null): AuthoritySnapshot|AuthorityGap
    {
        $work = LedgerRow::one(
            "SELECT stage, cohort_key, input_revision, encode(input_hash, 'hex') AS input_hash,
                    input_payload::text AS input_payload, input_schema_version
             FROM prc_process_work WHERE id = ?",
            [$workId->value->value],
        );
        if ($work === null) {
            return AuthorityGap::WorkMissing;
        }
        $code = StageCode::from($work->string('stage'));
        $handlerVersion = $executingVersion ?? $this->registry->handlerVersion($code);
        if ($handlerVersion === null) {
            return AuthorityGap::HandlerUnregistered;
        }
        $input = $this->codec->decodeInput(new VersionedPayload($work->string('input_payload'), $work->int('input_schema_version')));

        $scopeKeys = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->workScopes($code, $work->string('cohort_key')));
        sort($scopeKeys, SORT_STRING);
        $controls = LedgerRow::all(
            'SELECT id, scope_key, desired_mode, control_version FROM prc_control_scopes
             WHERE scope_key IN ('.LedgerRow::placeholders(count($scopeKeys)).') ORDER BY scope_key COLLATE "C"',
            $scopeKeys,
        );
        if (count($controls) !== count($scopeKeys)) {
            return AuthorityGap::ScopesMissing;
        }

        $handler = LedgerRow::one(
            'SELECT id, state, version FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ?',
            [$code->value, $handlerVersion],
        );
        if ($handler === null) {
            return AuthorityGap::HandlerAuthorityMissing;
        }

        $laneKeys = $this->policy->requiredLanes($code);
        $lanes = LedgerRow::all(
            'SELECT id FROM prc_process_lanes WHERE lane_key IN ('.LedgerRow::placeholders(count($laneKeys)).') ORDER BY id',
            $laneKeys,
        );
        if (count($lanes) !== count($laneKeys)) {
            return AuthorityGap::LanesMissing;
        }
        $laneIds = array_map(static fn (LedgerRow $lane): string => $lane->string('id'), $lanes);

        $documentId = null;
        if ($input instanceof PrepareInput || $input instanceof ScreeningInput) {
            $documentId = LedgerRow::one('SELECT document_id FROM prc_document_versions WHERE id=?', [$input->documentVersionId])?->int('document_id');
            if ($documentId === null) {
                return AuthorityGap::SubjectMissing;
            }
        }
        $subjects = [];
        foreach ($this->resolver->subjects($input, $documentId) as $subject) {
            $headColumn = $subject->kind === SubjectKind::DocumentVersion ? '1::bigint AS head_generation' : 'head_generation';
            $row = LedgerRow::one('SELECT '.$headColumn.' FROM '.$subject->kind->value.' WHERE id = ?', [self::subjectId($subject)]);
            if ($row === null) {
                return AuthorityGap::SubjectMissing;
            }
            $parent = $subject->kind === SubjectKind::DocumentVersion && $documentId !== null ? new SubjectKey(SubjectKind::Document, $documentId) : null;
            $subjects[] = new SubjectAuthoritySnapshot($subject, $parent, $row->int('head_generation'), null, null);
        }

        return new AuthoritySnapshot(
            workId: $workId,
            input: $input,
            inputRevision: $work->int('input_revision'),
            inputHash: new ContentHash($work->string('input_hash')),
            controls: array_map(static fn (LedgerRow $row): ControlSnapshot => new ControlSnapshot(
                new ProcessUuid($row->string('id')),
                $row->string('scope_key'),
                ControlMode::from($row->string('desired_mode')),
                $row->int('control_version'),
            ), $controls),
            handler: new HandlerAuthoritySnapshot(
                new ProcessUuid($handler->string('id')),
                $code,
                $handlerVersion,
                HandlerEligibility::from($handler->string('state')),
                $handler->int('version'),
            ),
            capacity: array_map(static fn (string $id): CapacityAuthority => new CapacityAuthority(CapacityKind::Lane, new ProcessUuid($id)), $laneIds),
            subjects: new SubjectLockSnapshot($subjects),
            admissionGroup: $this->activeGroup($workId, $laneIds),
            providerOperations: [],
        );
    }

    /**
     * Полный prefix в одной уже открытой ProcessTransaction. Admission/reconciler используют SKIP
     * LOCKED только на финальном work lock; claim конкретной доставки ждёт work lock.
     * Admission дополнительно фиксирует eligibility authorities всех выбранных пар ДО capacity.
     *
     * @param  list<AuthoritySnapshot>  $eligibilitySnapshots
     */
    public function lockPrefix(AuthoritySnapshot $snapshot, bool $skipLockedWork, array $eligibilitySnapshots = [], ?PublicationLocks $publication = null): LockedPrefix|PrefixFailure
    {
        CallerTransaction::require('Process lock prefix');

        // Tier 1: control closure FOR SHARE, sorted scope_key (byte order, как setMode) — параллельные
        // grants совместимы, pause сериализуется.
        $expectedControls = [];
        $expectedHandlers = [];
        $capacity = [];
        foreach ([$snapshot, ...$eligibilitySnapshots] as $authority) {
            foreach ($authority->controls as $scope) {
                $expectedControls[$scope->scopeKey] = $scope->id->value;
            }
            $expectedHandlers[$authority->handler->id->value] = $authority->handler;
            foreach ($authority->capacity as $member) {
                $capacity[$member->id->value] = $member;
            }
        }
        ksort($expectedControls, SORT_STRING);
        $controlIds = array_values($expectedControls);
        $controls = LedgerRow::all(
            'SELECT id, scope_key, desired_mode FROM prc_control_scopes
             WHERE id IN ('.LedgerRow::placeholders(count($controlIds)).') ORDER BY scope_key COLLATE "C" FOR SHARE',
            $controlIds,
        );
        $lockedKeys = array_map(static fn (LedgerRow $row): string => $row->string('scope_key'), $controls);
        $expectedKeys = array_keys($expectedControls);
        if ($lockedKeys !== $expectedKeys) {
            return PrefixFailure::SnapshotChanged;
        }
        $enabled = array_filter($controls, static fn (LedgerRow $row): bool => $row->string('desired_mode') === ControlMode::Enabled->value);
        $enabledKeys = array_values(array_map(static fn (LedgerRow $row): string => $row->string('scope_key'), $enabled));

        // Tier 1b: materialized handler-version eligibility FOR SHARE.
        $handlerIds = array_keys($expectedHandlers);
        $handlers = LedgerRow::all(
            'SELECT id, state FROM prc_process_handler_holds WHERE id IN ('.LedgerRow::placeholders(count($handlerIds)).')
             ORDER BY stage COLLATE "C", handler_version COLLATE "C" FOR SHARE',
            $handlerIds,
        );
        if (count($handlers) !== count($handlerIds)) {
            return PrefixFailure::SnapshotChanged;
        }
        $healthyIds = array_values(array_map(
            static fn (LedgerRow $row): string => $row->string('id'),
            array_filter($handlers, static fn (LedgerRow $row): bool => $row->string('state') === HandlerEligibility::Healthy->value),
        ));

        // Tier 2: весь capacity set одним sorted FOR UPDATE; COUNT reservations только после locks.
        $laneIds = array_keys($capacity);
        $in = LedgerRow::placeholders(count($laneIds));
        $laneRows = LedgerRow::all(
            "SELECT id, lane_key, parent_lane_id, max_outstanding, max_running,
                    fairness_cursor::text AS fairness_cursor, fairness_cursor_schema_version
             FROM prc_process_lanes WHERE id IN ({$in}) ORDER BY id FOR UPDATE",
            $laneIds,
        );
        if (count($laneRows) !== count($laneIds)) {
            return PrefixFailure::SnapshotChanged;
        }
        $counts = [];
        foreach (LedgerRow::all(
            "SELECT lane_id, count(*) AS outstanding, count(*) FILTER (WHERE state = 'running') AS running
             FROM prc_process_reservations WHERE lane_id IN ({$in}) GROUP BY lane_id",
            $laneIds,
        ) as $count) {
            $counts[$count->string('lane_id')] = [$count->int('outstanding'), $count->int('running')];
        }
        $lanes = [];
        foreach ($laneRows as $row) {
            [$outstanding, $running] = $counts[$row->string('id')] ?? [0, 0];
            $lanes[$row->string('lane_key')] = new LockedLane(
                $row->string('id'), $row->string('lane_key'), $row->nullableString('parent_lane_id'),
                $row->int('max_outstanding'), $row->int('max_running'), $outstanding, $running,
                $row->string('fairness_cursor'), $row->int('fairness_cursor_schema_version'),
            );
        }

        // Tier 3: полный subject set FOR UPDATE в порядке (table key, PK) снимка; heads сверяются.
        $publication?->lockIdentities();
        $subjects = $this->lockSubjects($snapshot);
        if ($subjects instanceof PrefixFailure) {
            return $subjects;
        }
        $publication?->lockSubjects();

        // Tier 4: work — последний lock prefix.
        $work = LedgerRow::one(
            "SELECT state, reason_code, cohort_key, platform_id, origin_run_id, input_revision, encode(input_hash, 'hex') AS input_hash,
                    intent_id, generation, owner_token, item_failures, dispatch_generation, transition_seq,
                    checkpoint::text AS checkpoint, checkpoint_schema_version, checkpoint_seq,
                    COALESCE(dispatch_claim_until <= clock_timestamp(), false) AS claim_expired,
                    COALESCE(lease_until <= clock_timestamp(), false) AS lease_expired,
                    COALESCE(state = 'deferred' AND due_at <= clock_timestamp(), false) AS due
             FROM prc_process_work WHERE id = ? FOR UPDATE".($skipLockedWork ? ' SKIP LOCKED' : ''),
            [$snapshot->workId->value->value],
        );
        if ($work === null) {
            $exists = LedgerRow::one('SELECT 1 AS present FROM prc_process_work WHERE id = ?', [$snapshot->workId->value->value]);

            return $exists === null ? PrefixFailure::WorkMissing : PrefixFailure::WorkLocked;
        }
        if ($work->int('input_revision') !== $snapshot->inputRevision || $work->string('input_hash') !== $snapshot->inputHash->hex) {
            throw new \LogicException('Work input is immutable for its work_key.');
        }

        $intentId = $work->nullableString('intent_id');
        $intent = $intentId === null ? null : LedgerRow::one('SELECT state FROM prc_operation_intents WHERE id = ?', [$intentId]);
        $group = LedgerRow::one(
            "SELECT id, state, dispatch_generation, version, encode(required_lane_set_hash, 'hex') AS lane_set_hash, attempt_id
             FROM prc_admission_groups WHERE work_id = ? AND state <> 'released'",
            [$snapshot->workId->value->value],
        );
        $members = [];
        if ($group !== null) {
            foreach (LedgerRow::all('SELECT lane_id, work_id, stage, state FROM prc_process_reservations WHERE admission_group_id = ? ORDER BY lane_id', [$group->string('id')]) as $member) {
                $members[$member->string('lane_id')] = $member;
            }
        }

        return new LockedPrefix(
            controlsEnabled: array_diff(array_map(static fn (ControlSnapshot $scope): string => $scope->scopeKey, $snapshot->controls), $enabledKeys) === [],
            handlerHealthy: in_array($snapshot->handler->id->value, $healthyIds, true),
            lanes: $lanes,
            subjects: $subjects,
            work: $work,
            intentActive: $intent === null ? $intentId === null : $intent->string('state') === 'active',
            group: $group,
            members: $members,
            enabledScopeKeys: $enabledKeys,
            healthyHandlerIds: $healthyIds,
            intentPending: $intent?->string('state') === 'pending',
        );
    }

    /**
     * Subject tier отдельно (checkpoint — subset prefix без controls/lanes, bp-lock-modes): полный
     * subject set FOR UPDATE в порядке снимка, heads сверяются; mutable subject не берётся SHARE.
     *
     * @return array<string, LockedSubject>|PrefixFailure keyed by SubjectKey::key()
     */
    public function lockSubjects(AuthoritySnapshot $snapshot): array|PrefixFailure
    {
        CallerTransaction::require('Process subject locks');
        $subjects = [];
        foreach ($snapshot->subjects->subjects as $expected) {
            $revision = $expected->subject->kind === SubjectKind::CrawlBlock ? 'input_revision' : 'NULL::bigint';
            $headColumn = $expected->subject->kind === SubjectKind::DocumentVersion ? '1::bigint AS head_generation' : 'head_generation';
            $currentVersion = $expected->subject->kind === SubjectKind::Document ? 'current_version_id' : 'NULL::bigint';
            $row = LedgerRow::one(
                "SELECT {$headColumn}, {$revision} AS input_revision, {$currentVersion} AS current_version_id FROM {$expected->subject->kind->value} WHERE id = ? FOR UPDATE",
                [self::subjectId($expected->subject)],
            );
            if ($row === null || $row->int('head_generation') !== $expected->headGeneration) {
                return PrefixFailure::SnapshotChanged;
            }
            $subjects[$expected->subject->key()] = new LockedSubject($expected->subject, $row->int('head_generation'), $row->nullableInt('input_revision'), $row->nullableInt('current_version_id'));
        }

        if ($snapshot->input instanceof PrepareInput || $snapshot->input instanceof ScreeningInput) {
            $version = LedgerRow::one('SELECT v.document_id,d.current_version_id FROM prc_document_versions v JOIN prc_documents d ON d.id=v.document_id WHERE v.id=?', [$snapshot->input->documentVersionId]);
            $parent = null;
            foreach ($snapshot->subjects->subjects as $s) {
                if ($s->subject->kind === SubjectKind::Document) {
                    $parent = self::subjectId($s->subject);
                }
            }
            if ($version === null || $version->int('document_id') !== $parent) {
                return PrefixFailure::SnapshotChanged;
            }

        }

        return $subjects;
    }

    /**
     * Exact membership: active group текущей delivery содержит ровно required lane set стадии,
     * его hash, work/stage identity и единое состояние members. Corruption не чинится молча.
     */
    public function exactMembership(AuthoritySnapshot $snapshot, LockedPrefix $prefix, string $groupState): bool
    {
        $group = $prefix->group;
        $laneIds = array_map(static fn (CapacityAuthority $authority): string => $authority->id->value, $snapshot->capacity);
        sort($laneIds, SORT_STRING);

        $expectedStage = $snapshot->handler->stage->value;
        foreach ($prefix->members as $member) {
            if ($member->string('work_id') !== $snapshot->workId->value->value
                || $member->string('stage') !== $expectedStage
                || $member->string('state') !== $groupState) {
                return false;
            }
        }

        return $group !== null
            && $group->string('state') === $groupState
            && $group->int('dispatch_generation') === $prefix->work->int('dispatch_generation')
            && $group->string('lane_set_hash') === $this->policy->requiredLaneSetHash($snapshot->handler->stage)->hex
            && array_keys($prefix->members) === $laneIds
            && ($groupState !== 'admitted' || $group->nullableString('attempt_id') === null);
    }

    /** @param list<string> $laneIds */
    private function activeGroup(WorkId $workId, array $laneIds): ?AdmissionGroupSnapshot
    {
        $group = LedgerRow::one(
            "SELECT id, dispatch_generation, version, encode(required_lane_set_hash, 'hex') AS lane_set_hash, attempt_id
             FROM prc_admission_groups WHERE work_id = ? AND state <> 'released'",
            [$workId->value->value],
        );
        if ($group === null) {
            return null;
        }
        $members = array_map(
            static fn (LedgerRow $row): string => $row->string('lane_id'),
            LedgerRow::all('SELECT lane_id FROM prc_process_reservations WHERE admission_group_id = ? ORDER BY lane_id', [$group->string('id')]),
        );
        // Неполный или чужой member set — не снимок, а corruption: его фиксирует locked membership check.
        if ($members === [] || array_diff($members, $laneIds) !== []) {
            return null;
        }
        $attemptId = $group->nullableString('attempt_id');

        return new AdmissionGroupSnapshot(
            new ProcessUuid($group->string('id')),
            $group->int('dispatch_generation'),
            $group->int('version'),
            new ContentHash($group->string('lane_set_hash')),
            array_map(static fn (string $id): ProcessUuid => new ProcessUuid($id), $members),
            $attemptId === null ? null : new ProcessUuid($attemptId),
        );
    }

    private static function subjectId(SubjectKey $subject): int|string
    {
        return is_int($subject->id) ? $subject->id : $subject->id->value;
    }
}
