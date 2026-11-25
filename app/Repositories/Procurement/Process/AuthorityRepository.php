<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CapacityAuthority;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\SubjectLockSnapshot;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CapacityKind;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;

/**
 * Authority pre-read и полный lock prefix.
 *
 * @phpstan-type EligibilityPair array{cohort: string, handler: HandlerAuthoritySnapshot}
 * @phpstan-type EligibilityAuthority array{controls: list<ControlSnapshot>, handler: HandlerAuthoritySnapshot, capacity: list<CapacityAuthority>}
 */
final readonly class AuthorityRepository
{
    public function __construct(
        private ProcessPayloadCodec $codec,
        private ProcessPolicy $policy,
        private StageRegistry $registry,
        private SubjectLockResolver $resolver,
        private ?SubjectRowLoader $loader = null,
    ) {}

    public function recoveryHandlerVersion(WorkId $workId, StageCode $stage, bool $preferAttempt): ?string
    {
        $attempt = LedgerRow::one(
            'SELECT handler_version FROM prc_process_attempts WHERE work_id = ? ORDER BY generation DESC LIMIT 1',
            [$workId->value->value],
        )?->string('handler_version');
        $registered = $this->registry->handlerVersion($stage);

        return $preferAttempt ? ($attempt ?? $registered) : ($registered ?? $attempt);
    }

    public function readRecoverySnapshot(WorkId $workId, bool $executing): AuthoritySnapshot|AuthorityGap
    {
        $row = LedgerRow::one('SELECT stage FROM prc_process_work WHERE id = ?', [$workId->value->value]);
        if ($row === null) {
            return AuthorityGap::WorkMissing;
        }

        return $this->readAuthoritySnapshot($workId, $this->recoveryHandlerVersion($workId, StageCode::from($row->string('stage')), $executing));
    }

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
        $controls = $this->loader()->readControlSnapshots($code, $work->string('cohort_key'));
        if ($controls instanceof AuthorityGap) {
            return $controls;
        }
        $handler = $this->loader()->readHandlerSnapshot($code, $handlerVersion);
        if (! $handler instanceof HandlerAuthoritySnapshot) {
            return $handler;
        }
        $laneIds = $this->loader()->readLaneIds($code);
        if ($laneIds instanceof AuthorityGap) {
            return $laneIds;
        }
        $subjects = $this->loader()->readSubjectSnapshots($input);
        if (! $subjects instanceof SubjectLockSnapshot) {
            return $subjects;
        }

        return new AuthoritySnapshot(
            workId: $workId,
            input: $input,
            inputRevision: $work->int('input_revision'),
            inputHash: new ContentHash($work->string('input_hash')),
            controls: $controls,
            handler: $handler,
            capacity: [
                ...array_map(static fn (string $id): CapacityAuthority => new CapacityAuthority(CapacityKind::Lane, new ProcessUuid($id)), $laneIds),
                ...$this->loader()->analysisStorage($workId, $code),
            ],
            subjects: $subjects,
            admissionGroup: $this->loader()->activeGroup($workId, $laneIds),
            providerOperations: [],
        );
    }

    /**
     * Admission fairness needs only early lock tiers for the competing pairs.
     *
     * @param  list<EligibilityPair>  $pairs
     * @return array<string, EligibilityAuthority|AuthorityGap>
     */
    public function readEligibilityAuthority(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $required = [];
        $scopeKeys = $laneKeys = [];
        foreach ($pairs as ['cohort' => $cohort, 'handler' => $handler]) {
            $stage = $handler->stage;
            $scopes = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->workScopes(stage: $stage, cohort: $cohort));
            $lanes = $this->policy->requiredLanes($stage);
            $required[$stage->value.':'.$cohort] = [$handler, $scopes, $lanes];
            array_push($scopeKeys, ...$scopes);
            array_push($laneKeys, ...$lanes);
        }
        $controlsByKey = $this->loader()->readControlSnapshotsByKey(array_values(array_unique($scopeKeys)));
        $laneIdsByKey = $this->loader()->readLaneIdsByKey(array_values(array_unique($laneKeys)));

        $eligibility = [];
        foreach ($required as $key => [$handler, $scopes, $lanes]) {
            $controls = [];
            foreach ($scopes as $scope) {
                if (! isset($controlsByKey[$scope])) {
                    $eligibility[$key] = AuthorityGap::ScopesMissing;

                    continue 2;
                }
                $controls[] = $controlsByKey[$scope];
            }
            $capacity = [];
            foreach ($lanes as $lane) {
                if (! isset($laneIdsByKey[$lane])) {
                    $eligibility[$key] = AuthorityGap::LanesMissing;

                    continue 2;
                }
                $capacity[] = new CapacityAuthority(CapacityKind::Lane, new ProcessUuid($laneIdsByKey[$lane]));
            }
            $eligibility[$key] = ['controls' => $controls, 'handler' => $handler, 'capacity' => $capacity];
        }

        return $eligibility;
    }

    /**
     * @param  list<AuthoritySnapshot|EligibilityAuthority>  $eligibilitySnapshots
     * @param  list<ProcessUuid>  $storageDomains
     */
    public function lockPrefix(AuthoritySnapshot $snapshot, bool $skipLockedWork, array $eligibilitySnapshots = [], ?PublicationLocks $publication = null,
        array $storageDomains = [], bool $readOnlyLane = false): LockedPrefix|PrefixFailure
    {
        CallerTransaction::require('Process lock prefix');

        $tier1 = $this->loader()->lockControlAndHandlerTiers([$snapshot, ...$eligibilitySnapshots]);
        if ($tier1 instanceof PrefixFailure) {
            return $tier1;
        }
        $enabledKeys = $tier1['enabledKeys'];
        $healthyIds = $tier1['healthyIds'];
        $capacity = $tier1['capacity'];

        $lanes = $this->loader()->lockLaneTier($capacity, $readOnlyLane);
        if ($lanes instanceof PrefixFailure) {
            return $lanes;
        }

        foreach ($capacity as $member) {
            if ($member->kind === CapacityKind::Storage) {
                $storageDomains[] = $member->id;
            }
        }
        $this->loader()->lockStorageDomains($storageDomains);

        $publication?->lockIdentities();
        $subjects = $this->lockSubjects($snapshot);
        if ($subjects instanceof PrefixFailure) {
            return $subjects;
        }
        $publication?->lockSubjects();

        $work = LedgerRow::one(
            "SELECT state, reason_code, cohort_key, platform_id, origin_run_id, priority, input_revision, encode(input_hash, 'hex') AS input_hash,
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
        if (! $this->sameAnalysisStorage($snapshot)) {
            return PrefixFailure::SnapshotChanged;
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

    /** @return array<string, LockedSubject>|PrefixFailure keyed by SubjectKey::key() */
    public function lockSubjects(AuthoritySnapshot $snapshot): array|PrefixFailure
    {
        return $this->loader()->lockSubjects($snapshot);
    }

    public static function headColumn(SubjectKind $kind): string
    {
        return SubjectRowLoader::headColumn($kind);
    }

    public function exactMembership(AuthoritySnapshot $snapshot, LockedPrefix $prefix, string $groupState): bool
    {
        $group = $prefix->group;
        $laneIds = array_map(
            static fn (CapacityAuthority $authority): string => $authority->id->value,
            array_values(array_filter($snapshot->capacity, static fn (CapacityAuthority $authority): bool => $authority->kind === CapacityKind::Lane)),
        );
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

    public function lockCheckpointStorage(AuthoritySnapshot $snapshot): void
    {
        $this->loader()->lockStorageDomains(array_map(
            static fn (CapacityAuthority $member): ProcessUuid => $member->id,
            array_values(array_filter($snapshot->capacity, static fn (CapacityAuthority $member): bool => $member->kind === CapacityKind::Storage)),
        ));
    }

    public function sameAnalysisStorage(AuthoritySnapshot $snapshot): bool
    {
        $expected = array_map(
            static fn (CapacityAuthority $member): string => $member->key(),
            array_values(array_filter($snapshot->capacity, static fn (CapacityAuthority $member): bool => $member->kind === CapacityKind::Storage)),
        );
        $actual = array_map(static fn (CapacityAuthority $member): string => $member->key(), $this->loader()->analysisStorage($snapshot->workId, $snapshot->handler->stage));
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);

        return $actual === $expected;
    }

    private function loader(): SubjectRowLoader
    {
        return $this->loader ?? new SubjectRowLoader($this->resolver, $this->policy);
    }
}
