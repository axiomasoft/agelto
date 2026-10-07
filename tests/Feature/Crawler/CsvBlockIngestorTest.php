<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\CsvBlockIngestor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Одна валидная строка блока (все колонки, которые читает `EisCsvRowMapper`) — фикстура
 * оверрайдится под сценарий по `Реестровый номер закупки`.
 *
 * @return array<string, string>
 */
function ingestorCsvRow(string $registryNumber, array $overrides = []): array
{
    return array_merge([
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'НМЦК' => '1 250 000,50',
        'Валюта' => 'RUB',
        'ОКПД2' => '26.20.11.110',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
    ], $overrides);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function ingestorBatch(array $rows, string $from = '1'): ListingBatch
{
    return new ListingBatch(
        rows: $rows,
        rawBody: 'raw csv body for batch from='.$from,
        dedupSignature: $rows[0]['Реестровый номер закупки'] ?? null,
        rowsTotal: count($rows),
        httpStatus: 200,
        durationMs: 123,
        requestParams: ['from' => $from, 'to' => '500'],
    );
}

test('фикстурный блок создаёт закупки, ревизии и события discovered', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
        ingestorCsvRow('0173200001234000002'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsTotal)->toBe(2)
        ->and($result->rowsParsed)->toBe(2)
        ->and($result->rowsNew)->toBe(2)
        ->and($result->rowsUpdated)->toBe(0)
        ->and($result->rowsSkipped)->toBe(0)
        ->and($result->rowsErrors)->toBe(0);

    expect(Procurement::query()->count())->toBe(2);

    $procurement = Procurement::query()->where('registry_number', '0173200001234000001')->first();
    expect($procurement)->not->toBeNull()
        ->and(ProcurementRevision::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->where('event_type', ProcurementEventType::Discovered)->count())->toBe(1);
});

test('передаёт точность самой старой даты обновления из маппера', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: ingestorBatch([
        ingestorCsvRow('0173200001234000011', ['Дата обновления' => '01.03.2026 00:00:00']),
        ingestorCsvRow('0173200001234000012', ['Дата обновления' => '01.03.2026']),
    ]));

    expect($result->oldestUpdateSeen->toDateString())->toBe('2026-03-01')
        ->and($result->oldestUpdateSeenHasDayPrecision)->toBeTrue();
});

test('неизменившаяся строка считается распарсенной, а не ошибкой', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ]);

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);
    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(0)
        ->and($result->rowsUpdated)->toBe(0)
        ->and($result->rowsParsed)->toBe(1)
        ->and($result->rowsErrors)->toBe(0)
        ->and(Procurement::query()->count())->toBe(1);

    $fetch = Fetch::query()->where('crawler_run_id', $run->id)->latest('id')->firstOrFail();
    expect($fetch->rows_parsed)->toBe(1)
        ->and($fetch->rows_errors)->toBe(0);
});

test('crw_fetches создаётся с dedup_signature, счётчиками и artifact_ref', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ], from: '501');

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    $fetch = Fetch::query()->where('crawler_run_id', $run->id)->first();

    expect($fetch)->not->toBeNull()
        ->and($fetch->dedup_signature)->toBe('0173200001234000001')
        ->and($fetch->rows_total)->toBe(1)
        ->and($fetch->rows_new)->toBe(1)
        ->and($fetch->rows_updated)->toBe(0)
        ->and($fetch->rows_parsed)->toBe(1)
        ->and($fetch->rows_errors)->toBe(0)
        ->and($fetch->artifact_ref)->toMatch("#^raw/csv/{$crawler->id}/{$run->id}/[a-f0-9]{64}/[a-f0-9]{64}\\.csv$#");
});

test('сырой CSV сохраняется по immutable content-addressed пути', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ], from: '1');

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    $path = Fetch::query()->where('crawler_run_id', $run->id)->sole()->artifact_ref;

    expect($path)->toMatch("#^raw/csv/{$crawler->id}/{$run->id}/[a-f0-9]{64}/[a-f0-9]{64}\\.csv$#");
    Storage::disk('s3')->assertExists($path);
    expect(Storage::disk('s3')->get($path))->toBe($batch->rawBody);
});

test('строка из crw_ignored_items пропускается и не создаёт закупку', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    IgnoredItem::factory()->for($registry)->create(['registry_number' => '0173200001234000099']);

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000099'),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsSkipped)->toBe(1)
        ->and($result->rowsNew)->toBe(1)
        ->and(Procurement::query()->where('registry_number', '0173200001234000099')->exists())->toBeFalse()
        ->and(Procurement::query()->where('registry_number', '0173200001234000001')->exists())->toBeTrue();
});

test('битая строка без источника номера не рушит блок — остальные строки обрабатываются', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('', ['Реестровый номер закупки' => '']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(1)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(Procurement::query()->where('registry_number', '0173200001234000001')->exists())->toBeTrue();
});

test('дедуп внутри блока по реестровому номеру — 510 строк на 500 уникальных дают 500 инжестов без вторых ревизий', function () {
    Storage::fake('s3');
    Log::spy();
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $rows = [];
    for ($i = 1; $i <= 500; $i++) {
        $rows[] = ingestorCsvRow(sprintf('017320000123400%04d', $i));
    }
    // Десять дублей ПОСЛЕ оригиналов — порядок сервера значимый, первая строка выигрывает.
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = ingestorCsvRow(sprintf('017320000123400%04d', $i), ['Наименование закупки' => 'ДУБЛЬ, обязан быть отброшен']);
    }

    $batch = ingestorBatch($rows);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsTotal)->toBe(510)
        ->and($result->rowsParsed)->toBe(500)
        ->and($result->rowsNew)->toBe(500)
        ->and($result->rowsDuplicated)->toBe(10)
        ->and($result->rowsParsed + $result->rowsSkipped + $result->rowsDuplicated + $result->rowsErrors)->toBe($result->rowsTotal)
        ->and(Procurement::query()->count())->toBe(500)
        ->and(ProcurementRevision::query()->count())->toBe(500);

    $firstDuplicated = Procurement::query()->where('registry_number', '0173200001234000001')->firstOrFail();
    expect($firstDuplicated->title)->toBe('Поставка оргтехники');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'схлопнуты дедупом') && $context['duplicates'] === 10)
        ->once();
});

test('битая строка с непарсящейся датой размещения не рушит блок', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000002', ['Дата размещения' => 'не дата']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(1)
        ->and($result->rowsParsed)->toBe(1)
        ->and($result->rowsSkipped)->toBe(1)
        ->and($result->rowsErrors)->toBe(0)
        ->and($result->rowsParsed + $result->rowsSkipped + $result->rowsDuplicated + $result->rowsErrors)->toBe($result->rowsTotal)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(Procurement::query()->where('registry_number', '0173200001234000001')->exists())->toBeTrue();
});

test('ошибка маппера записывается отдельно от пропущенной строки', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();
    $mapper = Mockery::mock(ListingRowMapper::class);
    $mapper->shouldReceive('map')->once()->andThrow(new RuntimeException('Ошибка маппинга'));
    app()->instance(abstract: ListingRowMapper::class, instance: $mapper);

    expect(fn () => app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ])))->toThrow(RuntimeException::class, 'не обработан полностью');

    $fetch = Fetch::query()->where('crawler_run_id', $run->id)->firstOrFail();
    expect($fetch->rows_parsed)->toBe(0)
        ->and($fetch->rows_errors)->toBe(1);
});

test('битые CSV-записи блока видны в rows_errors журнала, но не роняют блок', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();
    $rows = [ingestorCsvRow('0173200001234000001')];

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, new ListingBatch(
        rows: $rows,
        rawBody: 'raw csv body',
        dedupSignature: '0173200001234000001',
        rowsTotal: 2,
        httpStatus: 200,
        durationMs: 10,
        requestParams: ['from' => '1', 'to' => '500'],
        rowsMalformed: 1,
    ));

    $fetch = Fetch::query()->where('crawler_run_id', $run->id)->sole();

    expect($result->rowsErrors)->toBe(0)
        ->and($result->rowsParsed)->toBe(1)
        ->and($fetch->rows_total)->toBe(2)
        ->and($fetch->rows_errors)->toBe(1);
});
