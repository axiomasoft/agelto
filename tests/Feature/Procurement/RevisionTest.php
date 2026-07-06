<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\Registry;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\ProcurementIngestProcessor;
use Illuminate\Support\Carbon;
use Tests\Support\Procurement\SnapshotFactory;

/**
 * §11 «Изменилась цена/статус/даты» и «Состояние на дату X».
 */
test('изменение цены и статуса создаёт новую ревизию и события price_changed/status_changed', function () {
    $registry = Registry::factory()->create();
    $processor = app(ProcurementIngestProcessor::class);

    $procurement = $processor->ingest(SnapshotFactory::make($registry, [
        'sourceStatus' => 'Размещение извещения',
        'initialPrice' => '150000.00',
    ]));

    $updated = $processor->ingest(SnapshotFactory::make($registry, [
        'sourceStatus' => 'Работа комиссии',
        'initialPrice' => '175000.00',
    ]));

    expect($updated->id)->toBe($procurement->id)
        ->and($updated->revision_count)->toBe(2);

    $events = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->pluck('event_type');

    expect($events)->toContain(ProcurementEventType::StatusChanged)
        ->and($events)->toContain(ProcurementEventType::PriceChanged)
        ->and($events)->toContain(ProcurementEventType::RevisionPublished);
});

test('состояние на дату X читается по последней ревизии с detected_at <= X, таймлайн — по prc_events', function () {
    $registry = Registry::factory()->create();
    $processor = app(ProcurementIngestProcessor::class);

    // Партиции `prc_revisions` заведены помесячно вокруг текущей даты (см. `config/partitioning.php`),
    // поэтому даты сценария держим внутри текущего месяца, а не жёстко в прошлом.
    $day1 = Carbon::now()->subHours(3);
    $day2 = Carbon::now()->subHours(2);
    $day3 = Carbon::now()->subHour();

    Carbon::setTestNow($day1);
    $procurement = $processor->ingest(SnapshotFactory::make($registry, ['initialPrice' => '100000.00']));

    Carbon::setTestNow($day2);
    $processor->ingest(SnapshotFactory::make($registry, ['initialPrice' => '200000.00']));

    Carbon::setTestNow($day3);
    $processor->ingest(SnapshotFactory::make($registry, ['initialPrice' => '300000.00']));

    Carbon::setTestNow();

    $readRepository = app(ProcurementReadRepository::class);

    $stateBetweenDay1AndDay2 = $readRepository->findRevisionAsOf(procurementId: $procurement->id, asOf: $day2->copy()->subHour());
    expect($stateBetweenDay1AndDay2->revision_number)->toBe(1)
        ->and($stateBetweenDay1AndDay2->snapshot['initial_price'])->toBe('100000.00');

    $stateOnDay2 = $readRepository->findRevisionAsOf(procurementId: $procurement->id, asOf: $day2);
    expect($stateOnDay2->revision_number)->toBe(2);

    $stateAfterDay3 = $readRepository->findRevisionAsOf(procurementId: $procurement->id, asOf: $day3->copy()->addDay());
    expect($stateAfterDay3->revision_number)->toBe(3);

    $timeline = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->orderBy('occurred_at')
        ->pluck('event_type');

    expect($timeline->first())->toBe(ProcurementEventType::Discovered)
        ->and($timeline)->toContain(ProcurementEventType::PriceChanged);
});
