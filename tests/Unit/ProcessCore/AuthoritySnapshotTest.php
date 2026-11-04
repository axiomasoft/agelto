<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\AdmissionGroupSnapshot;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CapacityAuthority;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\ProviderOperationRef;
use App\Dto\Procurement\Process\SubjectAuthoritySnapshot;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\SubjectLockSnapshot;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\CapacityKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkState;
use Tests\Support\ProcessCore\ContractFixtures as F;

test('отсутствующий предок обнаруживается до обхода цепочки независимо от порядка строк', function (): void {
    $document = new SubjectKey(SubjectKind::Document, 2);
    $version = new SubjectKey(SubjectKind::DocumentVersion, 3);
    $missingProcurement = new SubjectKey(SubjectKind::Procurement, 1);
    $leaf = new SubjectAuthoritySnapshot($version, $document, 1, null, null);
    $parent = new SubjectAuthoritySnapshot($document, $missingProcurement, 1, null, null);

    foreach ([[$leaf, $parent], [$parent, $leaf]] as $subjects) {
        expect(fn () => new SubjectLockSnapshot($subjects))
            ->toThrow(InvalidArgumentException::class, 'Subject ancestor must already be loaded.');
    }
});

function fixtureAuthority(?SubjectLockSnapshot $subjects = null, ?AdmissionGroupSnapshot $group = null): AuthoritySnapshot
{
    return new AuthoritySnapshot(
        new WorkId(F::id()), new DetailInput(12, F::id(2), 1, F::hash(), F::hash(), 'v1'), 1, F::hash(),
        [new ControlSnapshot(F::id(3), 'system', ControlMode::Enabled, 1), new ControlSnapshot(F::id(4), 'acquisition', ControlMode::Paused, 7)],
        new HandlerAuthoritySnapshot(F::id(5), StageCode::Detail, 'handler-v1', HandlerEligibility::Healthy, 1),
        [new CapacityAuthority(CapacityKind::Storage, F::id(8)), new CapacityAuthority(CapacityKind::Lane, F::id(7))],
        $subjects ?? new SubjectLockSnapshot([new SubjectAuthoritySnapshot(new SubjectKey(SubjectKind::Procurement, 12), null, 3, null, null)]),
        $group,
        [new ProviderOperationRef(F::id(10), F::id(11), 4)],
    );
}

test('authority snapshot явно хранит controls handler capacity subject heads membership и provider linkage', function (): void {
    $group = new AdmissionGroupSnapshot(F::id(9), 1, 2, F::hash(), [F::id(7)], F::id(6));
    $snapshot = fixtureAuthority(group: $group);
    expect(array_map(fn ($scope) => $scope->scopeKey, $snapshot->controls))->toBe(['acquisition', 'system'])
        ->and($snapshot->controls[0]->mode)->toBe(ControlMode::Paused)
        ->and($snapshot->controls[0]->version)->toBe(7)
        ->and($snapshot->handler->handlerVersion)->toBe('handler-v1')
        ->and($snapshot->subjects->subjects[0]->headGeneration)->toBe(3)
        ->and($snapshot->admissionGroup)->toBe($group)
        ->and($snapshot->providerOperations[0]->requestEpoch)->toBe(4)
        ->and($snapshot->capacity[0]->kind)->toBe(CapacityKind::Lane);
    expect(fn () => $snapshot->inputRevision = 9)->toThrow(Error::class);
});

test('subject snapshot сохраняет реальные bigint PK ancestor links и numeric lock order', function (): void {
    $document = new SubjectKey(SubjectKind::Document, 2);
    $rows = [
        new SubjectAuthoritySnapshot(new SubjectKey(SubjectKind::DocumentVersion, 20), $document, null, 4, F::hash()),
        new SubjectAuthoritySnapshot(new SubjectKey(SubjectKind::Document, 10), null, 2, null, null),
        new SubjectAuthoritySnapshot($document, null, 3, null, null),
    ];
    $snapshot = new SubjectLockSnapshot($rows);
    expect(array_map(fn ($row) => $row->subject->id, $snapshot->subjects))->toBe([20, 2, 10]);
    expect(fn () => new SubjectKey(SubjectKind::Document, F::id()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SubjectKey(SubjectKind::AnalysisRun, 12))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SubjectLockSnapshot([$rows[0]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SubjectLockSnapshot([$rows[2], $rows[2]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SubjectLockSnapshot([new SubjectAuthoritySnapshot($document, $document, 1, null, null)]))->toThrow(InvalidArgumentException::class);
});

test('authority snapshot отклоняет missing capacity duplicate authority и чужой handler stage', function (): void {
    $snapshot = fixtureAuthority();
    expect(fn () => fixtureAuthority(group: new AdmissionGroupSnapshot(F::id(9), 1, 1, F::hash(), [F::id(99)], null)))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AuthoritySnapshot($snapshot->workId, $snapshot->input, 1, F::hash(), [], $snapshot->handler, $snapshot->capacity, $snapshot->subjects, null, []))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AuthoritySnapshot($snapshot->workId, $snapshot->input, 1, F::hash(), [$snapshot->controls[0], $snapshot->controls[0]], $snapshot->handler, $snapshot->capacity, $snapshot->subjects, null, []))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AuthoritySnapshot($snapshot->workId, $snapshot->input, 1, F::hash(), $snapshot->controls, new HandlerAuthoritySnapshot(F::id(5), StageCode::Download, 'v1', HandlerEligibility::Healthy, 1), $snapshot->capacity, $snapshot->subjects, null, []))->toThrow(InvalidArgumentException::class);
});

test('enums сохраняют точные конечные work stage control и deferral sets', function (): void {
    expect(array_column(WorkState::cases(), 'value'))->toBe(['ready', 'admitted', 'running', 'deferred', 'blocked', 'succeeded', 'failed_item', 'cancelled', 'superseded']);
    expect(array_column(StageCode::cases(), 'value'))->toBe(['listing', 'detail', 'status_refresh', 'download', 'prepare', 'screening', 'extraction', 'publication']);
    expect(array_column(ControlMode::cases(), 'value'))->toBe(['enabled', 'paused']);
    expect(array_column(DeferralReason::cases(), 'value'))->toBe(['no_route', 'target_cooldown', 'rate_limit', 'capacity_wait', 'item_retry', 'storage_unavailable', 'converter_unavailable', 'provider_unavailable', 'provider_budget', 'waiting_reference', 'lease_recovery', 'slice_exhausted', 'handler_fault']);
    expect(BlockedReason::HandlerFaultLimit->value)->toBe('handler_fault_limit');
});
