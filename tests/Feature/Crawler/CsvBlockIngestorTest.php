<?php

declare(strict_types=1);

use App\Dto\Crawler\ListingBatch;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Fetch;
use App\Models\Crawler\IgnoredItem;
use App\Models\Crawler\Run;
use App\Models\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Crawler\CsvBlockIngestor;
use Illuminate\Support\Facades\Storage;

/**
 * Одна валидная строка блока (все колонки, которые читает `EisCsvRowMapper`) — фикстура
 * оверрайдится под сценарий по `Реестровый номер закупки`.
 *
 * @return array<string, string>
 */
function ingestorCsvRow(string $sourceNumber, array $overrides = []): array
{
    return array_merge([
        'Реестровый номер закупки' => $sourceNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Размещение извещения',
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
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
        ingestorCsvRow('0173200001234000002'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsTotal)->toBe(2)
        ->and($result->rowsNew)->toBe(2)
        ->and($result->rowsUpdated)->toBe(0)
        ->and($result->rowsSkipped)->toBe(0)
        ->and($result->errors)->toBe(0);

    expect(Procurement::query()->count())->toBe(2);

    $procurement = Procurement::query()->where('source_number', '0173200001234000001')->first();
    expect($procurement)->not->toBeNull()
        ->and(ProcurementRevision::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->where('event_type', ProcurementEventType::Discovered)->count())->toBe(1);
});

test('повтор того же блока не создаёт дублей и rows_new=0', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ]);

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);
    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(0)
        ->and($result->rowsUpdated)->toBe(0)
        ->and(Procurement::query()->count())->toBe(1);
});

test('crw_fetches создаётся с dedup_signature, счётчиками и artifact_ref', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
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
        ->and($fetch->artifact_ref)->toBe("raw/csv/{$crawler->id}/{$run->id}/501.csv");
});

test('сырой CSV сохраняется в Storage по пути raw/csv/{crawlerId}/{runId}/{from}.csv', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ], from: '1');

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    $path = "raw/csv/{$crawler->id}/{$run->id}/1.csv";

    Storage::disk('s3')->assertExists($path);
    expect(Storage::disk('s3')->get($path))->toBe($batch->rawBody);
});

test('строка из crw_ignored_items пропускается и не создаёт закупку', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    IgnoredItem::factory()->for($registry)->create(['source_number' => '0173200001234000099']);

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000099'),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsSkipped)->toBe(1)
        ->and($result->rowsNew)->toBe(1)
        ->and(Procurement::query()->where('source_number', '0173200001234000099')->exists())->toBeFalse()
        ->and(Procurement::query()->where('source_number', '0173200001234000001')->exists())->toBeTrue();
});

test('битая строка без источника номера не рушит блок — остальные строки обрабатываются', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('', ['Реестровый номер закупки' => '']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(1)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(Procurement::query()->where('source_number', '0173200001234000001')->exists())->toBeTrue();
});

test('битая строка с непарсящейся датой размещения не рушит блок', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000002', ['Дата размещения' => 'не дата']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect($result->rowsNew)->toBe(1)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(Procurement::query()->where('source_number', '0173200001234000001')->exists())->toBeTrue();
});
