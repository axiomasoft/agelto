<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AdmissionGroupSnapshot;
use App\Dto\Procurement\Process\AnalysisSubjects;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CapacityAuthority;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectAuthoritySnapshot;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\SubjectLockSnapshot;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CapacityKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\SubjectLockResolver;

/**
 * Загрузчик и блокировщик subject-цепочки, control/handler/lane/storage tiers и active admission group.
 *
 * @phpstan-import-type EligibilityAuthority from AuthorityRepository
 */
final readonly class SubjectRowLoader
{
    public function __construct(
        private SubjectLockResolver $resolver,
        private ?ProcessPolicy $policy = null,
    ) {}

    /** @return list<ControlSnapshot>|AuthorityGap */
    public function readControlSnapshots(StageCode $code, string $cohortKey): array|AuthorityGap
    {
        $policy = $this->policy ?? app(ProcessPolicy::class);
        $scopeKeys = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $policy->workScopes($code, $cohortKey));
        $controls = $this->readControlSnapshotsByKey($scopeKeys);
        if (count($controls) !== count($scopeKeys)) {
            return AuthorityGap::ScopesMissing;
        }

        return array_values($controls);
    }

    /**
     * @param  list<string>  $scopeKeys
     * @return array<string, ControlSnapshot>
     */
    public function readControlSnapshotsByKey(array $scopeKeys): array
    {
        $controls = LedgerRow::all(
            'SELECT id, scope_key, desired_mode, control_version FROM prc_control_scopes
             WHERE scope_key IN ('.LedgerRow::placeholders(count($scopeKeys)).') ORDER BY scope_key COLLATE "C"',
            $scopeKeys,
        );
        $byKey = [];
        foreach ($controls as $row) {
            $key = $row->string('scope_key');
            $byKey[$key] = new ControlSnapshot(
                new ProcessUuid($row->string('id')),
                $key,
                ControlMode::from($row->string('desired_mode')),
                $row->int('control_version'),
            );
        }

        return $byKey;
    }

    public function readHandlerSnapshot(StageCode $code, string $handlerVersion): HandlerAuthoritySnapshot|AuthorityGap
    {
        $handler = LedgerRow::one(
            'SELECT id, state, version FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ?',
            [$code->value, $handlerVersion],
        );
        if ($handler === null) {
            return AuthorityGap::HandlerAuthorityMissing;
        }

        return new HandlerAuthoritySnapshot(
            new ProcessUuid($handler->string('id')),
            $code,
            $handlerVersion,
            HandlerEligibility::from($handler->string('state')),
            $handler->int('version'),
        );
    }

    /** @return list<string>|AuthorityGap */
    public function readLaneIds(StageCode $code): array|AuthorityGap
    {
        $policy = $this->policy ?? app(ProcessPolicy::class);
        $laneKeys = $policy->requiredLanes($code);
        $lanes = $this->readLaneIdsByKey($laneKeys);
        if (count($lanes) !== count($laneKeys)) {
            return AuthorityGap::LanesMissing;
        }

        return array_values($lanes);
    }

    /**
     * @param  list<string>  $laneKeys
     * @return array<string, string>
     */
    public function readLaneIdsByKey(array $laneKeys): array
    {
        $lanes = [];
        foreach (LedgerRow::all(
            'SELECT id, lane_key FROM prc_process_lanes WHERE lane_key IN ('.LedgerRow::placeholders(count($laneKeys)).') ORDER BY id',
            $laneKeys,
        ) as $row) {
            $lanes[$row->string('lane_key')] = $row->string('id');
        }

        return $lanes;
    }

    public function readSubjectSnapshots(WorkInput $input): SubjectLockSnapshot|AuthorityGap
    {
        $documentId = null;
        if ($input instanceof PrepareInput || $input instanceof ScreeningInput) {
            $documentId = LedgerRow::one('SELECT document_id FROM prc_document_versions WHERE id=?', [$input->documentVersionId])?->int('document_id');
            if ($documentId === null) {
                return AuthorityGap::SubjectMissing;
            }
        }
        $analysis = $this->analysisSubjects($input);
        if (($input instanceof ExtractionWindowInput || $input instanceof PublicationInput) && $analysis === null) {
            return AuthorityGap::SubjectMissing;
        }
        $subjects = [];
        foreach ($this->resolver->subjects($input, $documentId, $analysis) as $subject) {
            $headColumn = self::headColumn($subject->kind);
            $row = LedgerRow::one('SELECT '.$headColumn.' FROM '.$subject->kind->value.' WHERE id = ?', [self::subjectId($subject)]);
            if ($row === null) {
                return AuthorityGap::SubjectMissing;
            }
            $parent = $subject->kind === SubjectKind::DocumentVersion && $documentId !== null ? new SubjectKey(SubjectKind::Document, $documentId) : null;
            $subjects[] = new SubjectAuthoritySnapshot($subject, $parent, $row->int('head_generation'), null, null);
        }

        return new SubjectLockSnapshot($subjects);
    }

    /**
     * Tier 1 (control closure FOR SHARE) и Tier 1b (handler-version eligibility FOR SHARE).
     *
     * @param  list<AuthoritySnapshot|EligibilityAuthority>  $snapshots
     * @return array{enabledKeys: list<string>, healthyIds: list<string>, capacity: array<string, CapacityAuthority>}|PrefixFailure
     */
    public function lockControlAndHandlerTiers(array $snapshots): array|PrefixFailure
    {
        $expectedControls = [];
        $expectedHandlers = [];
        $capacity = [];
        foreach ($snapshots as $authority) {
            $controls = $authority instanceof AuthoritySnapshot ? $authority->controls : $authority['controls'];
            $handler = $authority instanceof AuthoritySnapshot ? $authority->handler : $authority['handler'];
            $members = $authority instanceof AuthoritySnapshot ? $authority->capacity : $authority['capacity'];
            foreach ($controls as $scope) {
                $expectedControls[$scope->scopeKey] = $scope->id->value;
            }
            $expectedHandlers[$handler->id->value] = $handler;
            foreach ($members as $member) {
                $capacity[$member->key()] = $member;
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
        if ($lockedKeys !== array_keys($expectedControls)) {
            return PrefixFailure::SnapshotChanged;
        }
        $enabled = array_filter($controls, static fn (LedgerRow $row): bool => $row->string('desired_mode') === ControlMode::Enabled->value);
        $enabledKeys = array_values(array_map(static fn (LedgerRow $row): string => $row->string('scope_key'), $enabled));

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

        return [
            'enabledKeys' => $enabledKeys,
            'healthyIds' => $healthyIds,
            'capacity' => $capacity,
        ];
    }

    /**
     * Tier 2: весь capacity set одним sorted FOR UPDATE; COUNT reservations только после locks.
     *
     * @param  array<string, CapacityAuthority>  $capacity
     * @return array<string, LockedLane>|PrefixFailure
     */
    public function lockLaneTier(array $capacity, bool $readOnly = false): array|PrefixFailure
    {
        $laneIds = array_map(
            static fn (CapacityAuthority $member): string => $member->id->value,
            array_values(array_filter($capacity, static fn (CapacityAuthority $member): bool => $member->kind === CapacityKind::Lane)),
        );
        sort($laneIds, SORT_STRING);
        $in = LedgerRow::placeholders(count($laneIds));
        // A running owner needs the exact current lane set, but does not mutate its
        // capacity reservation. Shared locks retain the prefix fence against lane
        // configuration changes while allowing independent admitted owners to validate.
        $lock = $readOnly ? 'FOR SHARE' : 'FOR UPDATE';
        $laneRows = LedgerRow::all(
            "SELECT id, lane_key, parent_lane_id, max_outstanding, max_running,
                    fairness_cursor::text AS fairness_cursor, fairness_cursor_schema_version
             FROM prc_process_lanes WHERE id IN ({$in}) ORDER BY id {$lock}",
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

        return $lanes;
    }

    /**
     * @return array<string, LockedSubject>|PrefixFailure keyed by SubjectKey::key()
     */
    public function lockSubjects(AuthoritySnapshot $snapshot): array|PrefixFailure
    {
        CallerTransaction::require('Process subject locks');
        $subjects = [];
        foreach ($snapshot->subjects->subjects as $expected) {
            $revision = $expected->subject->kind === SubjectKind::CrawlBlock ? 'input_revision' : 'NULL::bigint';
            $headColumn = self::headColumn($expected->subject->kind);
            $currentVersion = $expected->subject->kind === SubjectKind::Document ? 'current_version_id' : 'NULL::bigint';
            $extra = match ($expected->subject->kind) {
                SubjectKind::DocumentVersion => "analysis_generation,current_analysis_run_id,
                    (SELECT r.expected_document_head_generation FROM prc_analysis_runs r WHERE r.id=current_analysis_run_id) AS expected_document_head_generation,
                    (SELECT encode(r.recipe_hash,'hex') FROM prc_analysis_runs r WHERE r.id=current_analysis_run_id) AS current_recipe_hex,
                    (SELECT encode(r.representation_hash,'hex') FROM prc_analysis_runs r WHERE r.id=current_analysis_run_id) AS current_representation_hex",
                SubjectKind::AnalysisRun => "encode(recipe_hash,'hex') AS recipe_hex,expected_document_head_generation,expected_analysis_generation",
                default => 'NULL::bigint AS analysis_generation',
            };
            $row = LedgerRow::one(
                "SELECT {$headColumn}, {$revision} AS input_revision, {$currentVersion} AS current_version_id,{$extra} FROM {$expected->subject->kind->value} WHERE id = ? FOR UPDATE",
                [self::subjectId($expected->subject)],
            );
            if ($row === null || $row->int('head_generation') !== $expected->headGeneration) {
                return PrefixFailure::SnapshotChanged;
            }
            $kind = $expected->subject->kind;
            $currentRun = $kind === SubjectKind::DocumentVersion ? $row->nullableString('current_analysis_run_id') : null;
            $subjects[$expected->subject->key()] = new LockedSubject(
                $expected->subject,
                $row->int('head_generation'),
                $row->nullableInt('input_revision'),
                $row->nullableInt('current_version_id'),
                $kind === SubjectKind::DocumentVersion ? $row->int('analysis_generation') : null,
                $currentRun === null ? null : new ProcessUuid($currentRun),
                $kind === SubjectKind::AnalysisRun ? new ContentHash($row->string('recipe_hex')) : null,
                in_array($kind, [SubjectKind::AnalysisRun, SubjectKind::DocumentVersion], true) ? $row->nullableInt('expected_document_head_generation') : null,
                $kind === SubjectKind::AnalysisRun ? $row->int('expected_analysis_generation') : null,
                ($hash = $row->nullableString('current_recipe_hex')) === null ? null : new ContentHash($hash),
                ($hash = $row->nullableString('current_representation_hex')) === null ? null : new ContentHash($hash),
            );
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

        if ($snapshot->input instanceof ExtractionWindowInput || $snapshot->input instanceof PublicationInput) {
            $current = $this->analysisSubjects($snapshot->input);
            if ($current === null) {
                return PrefixFailure::SnapshotChanged;
            }
            $keys = array_map(static fn (SubjectKey $s): string => $s->key(), $this->resolver->subjects($snapshot->input, analysis: $current));
            $expectedKeys = array_keys($subjects);
            sort($keys);
            sort($expectedKeys);
            if ($keys !== $expectedKeys) {
                return PrefixFailure::SnapshotChanged;
            }
        }

        return $subjects;
    }

    public static function headColumn(SubjectKind $kind): string
    {
        return match ($kind) {
            SubjectKind::DocumentVersion, SubjectKind::AnalysisWindow => '1::bigint AS head_generation',
            SubjectKind::AnalysisRun => 'generation AS head_generation',
            default => 'head_generation',
        };
    }

    public function analysisSubjects(WorkInput $input): ?AnalysisSubjects
    {
        if (! $input instanceof ExtractionWindowInput && ! $input instanceof PublicationInput) {
            return null;
        }
        $window = $input instanceof ExtractionWindowInput;
        $row = LedgerRow::one(
            'SELECT v.document_id,v.id AS version_id,r.id AS run_id,'.($window ? 'w.id' : 'NULL::uuid').
            ' AS window_id FROM prc_analysis_runs r JOIN prc_document_versions v ON v.id=r.document_version_id '.
            ($window ? 'JOIN prc_analysis_windows w ON w.run_id=r.id ' : '').'WHERE r.id=?'.($window ? ' AND w.window_key=?' : ''),
            [$input->analysisRunId->value, ...($window ? [$input->windowKey] : [])],
        );

        return $row === null ? null : new AnalysisSubjects(
            $row->int('document_id'),
            $row->int('version_id'),
            new ProcessUuid($row->string('run_id')),
            ($id = $row->nullableString('window_id')) === null ? null : new ProcessUuid($id),
        );
    }

    /** @return list<CapacityAuthority> */
    public function analysisStorage(WorkId $work, StageCode $code): array
    {
        $required = $this->resolver->requiresAnalysisStorageLocks($code);
        if (! $required) {
            return [];
        }

        return array_map(
            static fn (LedgerRow $row): CapacityAuthority => new CapacityAuthority(CapacityKind::Storage, new ProcessUuid($row->string('domain_id'))),
            LedgerRow::all('SELECT DISTINCT domain_id FROM prc_storage_reservations WHERE work_id=? ORDER BY domain_id', [$work->value->value]),
        );
    }

    /** @param list<ProcessUuid> $domains */
    public function lockStorageDomains(array $domains): void
    {
        $ids = array_values(array_unique(array_map(static fn (ProcessUuid $id): string => $id->value, $domains)));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (LedgerRow::one('SELECT id FROM prc_storage_domains WHERE id=? FOR UPDATE', [$id]) === null) {
                throw new \LogicException('Missing declared storage capacity authority.');
            }
        }
    }

    /** @param list<string> $laneIds */
    public function activeGroup(WorkId $workId, array $laneIds): ?AdmissionGroupSnapshot
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
