<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Events\Procurement\ProcurementSucceeded;
use App\Models\Organization\Organization;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Procurement\SnapshotFactory;

function succeededEventRegistry(string $stage, ProcurementStatus $status): Registry
{
    $platform = Platform::factory()->create(['code' => 'eis', 'uuid' => (string) Str::uuid7()]);
    $registry = Registry::factory()->for($platform)->create(['uuid' => (string) Str::uuid7()]);

    StatusMapping::factory()->create([
        'registry_id' => $registry->id,
        'source_stage' => $stage,
        'canonical_status' => $status,
    ]);

    return $registry;
}

function successfulAwardData(Organization $winner): SupplierResultsData
{
    return new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(fullName: $winner->full_name, inn: $winner->inn, kpp: $winner->kpp),
            finalPrice: 9000000.0,
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    );
}

test('переход в completed рождает событие успешной закупки', function () {
    Event::fake();
    $registry = succeededEventRegistry('Завершена', ProcurementStatus::Completed);

    $procurement = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => 'Завершена',
    ]));

    Event::assertDispatched(ProcurementSucceeded::class, function (ProcurementSucceeded $event) use ($procurement): bool {
        return $event->procurementId === $procurement->id
            && $event->reason === 'status'
            && $event->canonicalStatus === ProcurementStatus::Completed->value;
    });
    expect($procurement->fresh()->succeeded_at)->not->toBeNull();
});

test('award с идентифицированным победителем рождает событие без смены статуса', function () {
    Event::fake();
    $registry = succeededEventRegistry('Подача заявок', ProcurementStatus::Bidding);
    $procurement = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry));
    $winner = Organization::factory()->create(['uuid' => (string) Str::uuid7()]);

    app(SupplierResultsProcessor::class)->process($procurement, successfulAwardData($winner));

    Event::assertDispatched(ProcurementSucceeded::class, fn (ProcurementSucceeded $event): bool => $event->procurementId === $procurement->id
        && $event->reason === 'award'
        && $event->winnerOrgId === $winner->id
        && $event->canonicalStatus === ProcurementStatus::Bidding->value);
});

test('повторный ingest не рождает второе событие успешной закупки', function () {
    Event::fake();
    $registry = succeededEventRegistry('Завершена', ProcurementStatus::Completed);
    $processor = app(SnapshotFinalizer::class);
    $snapshot = SnapshotFactory::make($registry, ['sourceStage' => 'Завершена']);

    $procurement = $processor->ingest($snapshot);
    $processor->ingest($snapshot);

    Event::assertDispatchedTimes(ProcurementSucceeded::class, 1);
    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::Succeeded)
        ->count())->toBe(1);
});

test('отрицательные и неизвестный статусы не рождают событие успешной закупки', function (ProcurementStatus $status) {
    Event::fake();
    $registry = succeededEventRegistry("Статус {$status->value}", $status);

    app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => "Статус {$status->value}",
    ]));

    Event::assertNotDispatched(ProcurementSucceeded::class);
})->with([ProcurementStatus::Unknown, ProcurementStatus::Cancelled, ProcurementStatus::Unsuccessful]);

test('нагрузка несёт снимок отсутствующих на момент исхода смет', function () {
    Event::fake();
    $registry = succeededEventRegistry('Завершена', ProcurementStatus::Completed);
    $result = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => 'Завершена',
    ]));

    Event::assertDispatched(ProcurementSucceeded::class, fn (ProcurementSucceeded $event): bool => $event->procurementId === $result->id
        && ! $event->hasEstimate
        && $event->estimatesCount === 0);
});

test('откат транзакции не диспатчит событие успешной закупки', function () {
    Event::fake();
    $registry = succeededEventRegistry('Завершена', ProcurementStatus::Completed);

    try {
        DB::transaction(function () use ($registry): void {
            app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
                'sourceStage' => 'Завершена',
            ]));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // Expected: the surrounding transaction rolls back and cancels after-commit callbacks.
    }

    Event::assertNotDispatched(ProcurementSucceeded::class);
});
