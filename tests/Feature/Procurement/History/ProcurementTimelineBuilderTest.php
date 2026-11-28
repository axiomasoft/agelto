<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\History\ProcurementHistoryReadRepository;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use App\Services\Procurement\History\ProcurementTimelineBuilder;
use Illuminate\Support\Carbon;
use Tests\Support\Procurement\SnapshotFactory;

function historyMoment(string $time): Carbon
{
    return Carbon::parse("2026-08-08 {$time}");
}

function historyEvent(Procurement $procurement, ProcurementEventType $type, string $time, ?string $newValue = null): ProcurementEvent
{
    return ProcurementEvent::factory()->for($procurement, 'procurement')->create([
        'event_type' => $type,
        'occurred_at' => historyMoment($time),
        'field' => $type === ProcurementEventType::StatusChanged ? 'status' : null,
        'new_value' => $newValue,
    ]);
}

test('длительности статусов считаются по доменным временам трёх переходов', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => historyMoment('09:00:00')]);
    historyEvent($procurement, ProcurementEventType::Discovered, '10:00:00');
    historyEvent($procurement, ProcurementEventType::StatusChanged, '11:00:00', ProcurementStatus::Bidding->value);
    historyEvent($procurement, ProcurementEventType::StatusChanged, '13:00:00', ProcurementStatus::Evaluation->value);
    historyEvent($procurement, ProcurementEventType::StatusChanged, '16:00:00', ProcurementStatus::Completed->value);

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->durations->publishedAt->toDateTimeString())->toBe('2026-08-08 09:00:00')
        ->and($timeline->durations->terminalStatus)->toBe(ProcurementStatus::Completed)
        ->and($timeline->durations->totalDuration)->toBe(21600)
        ->and($timeline->durations->byStatus)->toBe([
            ProcurementStatus::Bidding->value => 7200,
            ProcurementStatus::Evaluation->value => 10800,
        ]);
});

test('незавершённая закупка сохраняет честную неопределённость общей длительности', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => historyMoment('09:00:00')]);
    historyEvent($procurement, ProcurementEventType::Discovered, '10:00:00');
    historyEvent($procurement, ProcurementEventType::StatusChanged, '11:00:00', ProcurementStatus::Bidding->value);

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->durations->terminalStatus)->toBeNull()
        ->and($timeline->durations->terminalAt)->toBeNull()
        ->and($timeline->durations->totalDuration)->toBeNull();
});

test('ingest фиксирует наблюдаемую длительность bidding → evaluation → completed', function (): void {
    $registry = Registry::factory()->eis()->create();
    StatusMapping::factory()->createMany([
        ['registry_id' => $registry->id, 'source_stage' => 'Подача заявок', 'canonical_status' => ProcurementStatus::Bidding->value],
        ['registry_id' => $registry->id, 'source_stage' => 'Работа комиссии', 'canonical_status' => ProcurementStatus::Evaluation->value],
        ['registry_id' => $registry->id, 'source_stage' => 'Закупка завершена', 'canonical_status' => ProcurementStatus::Completed->value],
    ]);
    $processor = app(SnapshotFinalizer::class);
    $originalNow = Carbon::getTestNow();

    try {
        Carbon::setTestNow(historyMoment('10:00:00'));
        $procurement = $processor->ingest(SnapshotFactory::make($registry, ['sourceStage' => 'Подача заявок']));

        Carbon::setTestNow(historyMoment('12:00:00'));
        $processor->ingest(SnapshotFactory::make($registry, ['sourceStage' => 'Работа комиссии']));

        Carbon::setTestNow(historyMoment('15:00:00'));
        $processor->ingest(SnapshotFactory::make($registry, ['sourceStage' => 'Закупка завершена']));
    } finally {
        Carbon::setTestNow($originalNow);
    }

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->durations->terminalStatus)->toBe(ProcurementStatus::Completed)
        ->and($timeline->durations->terminalAt?->toDateTimeString())->toBe('2026-08-08 15:00:00')
        ->and($timeline->durations->totalDuration)->toBe(18000)
        ->and($timeline->durations->byStatus)->toBe([
            ProcurementStatus::Bidding->value => 7200,
            ProcurementStatus::Evaluation->value => 10800,
        ]);
});

test('закупка, завершённая при discovery, терминальна с нулевой наблюдаемой длительностью', function (): void {
    $registry = Registry::factory()->eis()->create();
    StatusMapping::factory()->create([
        'registry_id' => $registry->id,
        'source_stage' => 'Закупка завершена',
        'canonical_status' => ProcurementStatus::Completed->value,
    ]);
    $originalNow = Carbon::getTestNow();

    try {
        Carbon::setTestNow(historyMoment('10:00:00'));
        $procurement = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry, [
            'sourceStage' => 'Закупка завершена',
        ]));
    } finally {
        Carbon::setTestNow($originalNow);
    }

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->durations->terminalStatus)->toBe(ProcurementStatus::Completed)
        ->and($timeline->durations->terminalAt?->toDateTimeString())->toBe('2026-08-08 10:00:00')
        ->and($timeline->durations->totalDuration)->toBe(0)
        ->and($timeline->durations->byStatus)->toBe([ProcurementStatus::Completed->value => 0]);
});

test('история только с discovered использует статус одной ревизии без выдуманной длительности', function (): void {
    $procurement = Procurement::factory()->create([
        'status' => ProcurementStatus::Completed,
        'revision_count' => 1,
        'published_at' => historyMoment('09:00:00'),
    ]);
    historyEvent($procurement, ProcurementEventType::Discovered, '10:00:00');

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->durations->terminalStatus)->toBe(ProcurementStatus::Completed)
        ->and($timeline->durations->terminalAt?->toDateTimeString())->toBe('2026-08-08 10:00:00')
        ->and($timeline->durations->totalDuration)->toBe(0)
        ->and($timeline->durations->byStatus)->toBe([ProcurementStatus::Completed->value => 0]);
});

test('пустая история возвращает пустую ленту без исключения', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => historyMoment('09:00:00')]);

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->entries)->toBe([])
        ->and($timeline->durations->discoveredAt)->toBeNull()
        ->and($timeline->durations->totalDuration)->toBeNull();
});

test('лента связывает событие с номером редакции только из заданного окна', function (): void {
    $procurement = Procurement::factory()->create(['published_at' => historyMoment('09:00:00')]);
    $revision = $procurement->revisions()->create([
        'revision_number' => 3,
        'snapshot' => ['title' => 'Редакция'],
        'snapshot_hash' => 'history-revision',
        'detected_at' => historyMoment('12:00:00'),
    ]);
    $event = historyEvent($procurement, ProcurementEventType::RevisionPublished, '12:00:00');
    $event->update(['revision_id' => $revision->id]);

    $timeline = app(ProcurementTimelineBuilder::class)->build($procurement, historyMoment('08:00:00'), historyMoment('17:00:00'));

    expect($timeline->entries)->toHaveCount(1)
        ->and($timeline->entries[0]->revisionNumber)->toBe(3);
});

test('read репозиторий принимает временную границу как обязательную часть каждого метода', function (): void {
    $repository = new ReflectionClass(ProcurementHistoryReadRepository::class);

    foreach (['paginateEvents', 'paginateRevisions', 'eventsForTimeline', 'revisionsForTimeline'] as $method) {
        expect($repository->getMethod($method)->getNumberOfRequiredParameters())->toBe(3);
    }
});
