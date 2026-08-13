<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Eis\EisCsvRowMapper;
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
        'sourceStage' => 'Подача заявок',
        'initialPrice' => '150000.00',
    ]));

    $updated = $processor->ingest(SnapshotFactory::make($registry, [
        'sourceStage' => 'Работа комиссии',
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

/**
 * Дефект Д-1: `EisCsvRowMapper::parseDate()` без модификатора `!` подставляет ТЕКУЩЕЕ время
 * в компоненты, не указанные форматом источника (`Дата размещения` несёт только `d.m.Y`),
 * поэтому `snapshot_hash` двух парсингов одной и той же строки различался бы из-за времени
 * инжеста, а не факта источника. Снапшот собирается через РЕАЛЬНЫЙ `EisCsvRowMapper` (не
 * `SnapshotFactory` — он строит `Carbon` напрямую, минуя `parseDate`, и дефект не ловит).
 */
test('повторный инжест идентичного CSV-снапшота ЕИС не создаёт вторую ревизию и событие (Д-1)', function () {
    $crawler = Crawler::factory()->create();
    $registry = $crawler->registry;
    $mapper = app(EisCsvRowMapper::class);
    $processor = app(ProcurementIngestProcessor::class);

    $row = [
        'Реестровый номер закупки' => '0173100000123000001',
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка бумаги офисной',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'Начальная (максимальная) цена контракта' => '150000,00',
        'Валюта' => 'RUB',
        'Классификация по ОКПД2' => '',
        'Дата размещения' => '10.01.2026',
        'Дата обновления' => '10.01.2026 10:00:00',
        'Начало подачи заявок' => '',
        'Окончание подачи заявок' => '',
        'Наименование Заказчика' => 'ГБУ «Тестовое учреждение»',
    ];

    $first = $processor->ingest($mapper->map(row: $row, crawler: $crawler));

    // Первый инжест сам вправе создать больше одного события (незнакомая пара статусов
    // резолвится как newly-mapped) — базовая линия снимается ПОСЛЕ него, а не жёстко «1».
    $revisionsAfterFirst = ProcurementRevision::query()->where('procurement_id', $first->id)->count();
    $eventsAfterFirst = ProcurementEvent::query()->where('procurement_id', $first->id)->count();

    // Между инжестами системное время ОБЯЗАНО отличаться (иначе тест не ловит Д-1, а совпадает
    // случайно): пауза гарантирует переход через границу секунды.
    usleep(1_100_000);

    $second = $processor->ingest($mapper->map(row: $row, crawler: $crawler));

    expect($second->id)->toBe($first->id)
        ->and($second->revision_count)->toBe($revisionsAfterFirst);

    expect(ProcurementRevision::query()->where('procurement_id', $first->id)->count())->toBe($revisionsAfterFirst)
        ->and(ProcurementEvent::query()->where('procurement_id', $first->id)->count())->toBe($eventsAfterFirst);
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
