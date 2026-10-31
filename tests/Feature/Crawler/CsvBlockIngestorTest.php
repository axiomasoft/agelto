<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
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

    $procurement = Procurement::query()->where('source_identity_value', '0173200001234000001')->first();
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
        ingestorCsvRow('0173200001234000011', ['Дата обновления' => '01.03.2026 03:00:00']),
        ingestorCsvRow('0173200001234000012', ['Дата обновления' => '01.03.2026']),
    ]));

    // Сутки 01.03 по МСК начинаются 28.02 в 21:00 UTC — это раньше, чем 01.03 03:00 МСК.
    expect($result->oldestUpdateSeen->toIso8601String())->toBe('2026-02-28T21:00:00+00:00')
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

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and($result->rowsParsed)->toBe(1)->and($result->rowsErrors)->toBe(0);
});

test('crw_fetches создаётся с dedup_signature, счётчиками и artifact_ref', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ], from: '501');

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    $artifact = RawArtifact::query()->where('path', 'like', "raw/csv/{$crawler->id}/{$run->id}/%")->sole();

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and($result->rowsTotal)->toBe(1)
        ->and($result->rowsNew)->toBe(1)
        ->and($result->rowsUpdated)->toBe(0)
        ->and($result->rowsParsed)->toBe(1)
        ->and($result->rowsErrors)->toBe(0)
        ->and($artifact->path)->toMatch("#^raw/csv/{$crawler->id}/{$run->id}/[a-f0-9]{64}/[a-f0-9]{64}\\.csv$#");
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

    $path = RawArtifact::query()->where('path', 'like', "raw/csv/{$crawler->id}/{$run->id}/%")->sole()->path;

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
        ->and(Procurement::query()->where('source_identity_value', '0173200001234000099')->exists())->toBeFalse()
        ->and(Procurement::query()->where('source_identity_value', '0173200001234000001')->exists())->toBeTrue();
});

test('битая строка без номера оставляет блок незавершённым', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('', ['Реестровый номер закупки' => '']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);
    expect($result->failure)->toBe(SourceFailureKind::RowErrors)
        ->and($result->rowsErrors)->toBe(1);

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and(Procurement::query()->count())->toBe(1)
        ->and(Procurement::query()->where('source_identity_value', '0173200001234000001')->exists())->toBeTrue();
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

    $firstDuplicated = Procurement::query()->where('source_identity_value', '0173200001234000001')->firstOrFail();
    expect($firstDuplicated->title)->toBe('Поставка оргтехники');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'схлопнуты дедупом') && $context['duplicates'] === 10)
        ->once();
});

test('битая строка с неверной датой оставляет блок незавершённым', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000002', ['Дата размещения' => 'не дата']),
        ingestorCsvRow('0173200001234000001'),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);
    expect($result->failure)->toBe(SourceFailureKind::RowErrors)
        ->and($result->rowsErrors)->toBe(1);

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and(Procurement::query()->where('source_identity_value', '0173200001234000001')->exists())->toBeTrue();
});

test('ошибка маппера записывается отдельно от пропущенной строки', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();
    $mapper = Mockery::mock(ListingRowMapper::class);
    $mapper->shouldReceive('map')->once()->andThrow(new RuntimeException('Ошибка маппинга'));
    config()->set('crawler.platforms.eis.listing_mapper', ListingRowMapper::class);
    app()->instance(abstract: ListingRowMapper::class, instance: $mapper);

    $result = app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: ingestorBatch([
        ingestorCsvRow('0173200001234000001'),
    ]));

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and($result->rowsParsed)->toBe(0)
        ->and($result->rowsErrors)->toBe(1)
        // Ни одна запись не стала снимком — блок остаётся для повторной обработки.
        ->and($result->failure)->toBe(SourceFailureKind::InvalidContent)
        ->and($result->fetchId)->toBeNull();
});

test('битые CSV-записи учитываются в результате и журнале, блок остаётся неполным', function (): void {
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

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);

    expect($result->rowsErrors)->toBe(1)
        ->and($result->failure)->toBe(SourceFailureKind::RowErrors)
        ->and($result->rowsParsed)->toBe(1)
        ->and($result->rowsTotal)->toBe(2)
        ->and($result->rowsErrors)->toBe(1);
});

test('страница ошибки источника не инжестится; ингестор только классифицирует, инцидент пишет вызывающий', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, new ListingBatch(
        rows: [ingestorCsvRow('0173200001234000001')],
        rawBody: '<html>Доступ ограничен</html>',
        dedupSignature: null,
        rowsTotal: 1,
        httpStatus: 403,
        durationMs: 10,
        requestParams: ['from' => '1', 'to' => '500'],
    ));

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);

    expect(Procurement::query()->count())->toBe(0)
        ->and($result->httpStatus)->toBe(403)
        ->and($result->failure)->toBe(SourceFailureKind::SourceBlocked)
        ->and($result->fetchId)->toBeNull()
        ->and(SourceIncident::query()->count())->toBe(0);
});

test('блок, ни одна запись которого не распознана маппером, — ответ не по формату', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    // Источник переименовал колонку даты: маппер не может построить ни одного снапшота.
    $batch = ingestorBatch([
        ingestorCsvRow('0173200001234000001', ['Дата размещения' => '2026-03-01']),
        ingestorCsvRow('0173200001234000002', ['Дата размещения' => '2026-03-01']),
    ]);

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, $batch);

    expect($result->failure)->toBe(SourceFailureKind::InvalidContent)
        ->and(Procurement::query()->count())->toBe(0);
});

test('пустой блок не считается отказом', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, ingestorBatch([]));

    expect($result->rowsTotal)->toBe(0)
        ->and($result->failure)->toBeNull();
});

test('нет обязательной колонки в заголовке — ответ не по формату, строки не разбираются', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();
    $row = ingestorCsvRow('0173200001234000001');
    unset($row['Дата обновления']);

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, ingestorBatch([$row]));

    expect($result->failure)->toBe(SourceFailureKind::LayoutDrift)
        ->and($result->failureDetail)->toContain('Нет колонок: Дата обновления')
        ->and(Procurement::query()->count())->toBe(0);
});

test('HTML вместо CSV с HTTP 200 ловится проверкой заголовка, даже если записей нет', function (): void {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    // Однострочная HTML-заглушка: записей данных 0, но заголовок не тот. Раньше такой блок
    // выглядел бы как «пустой» и закрывал бы интервал архива.
    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, new ListingBatch(
        rows: [],
        rawBody: '<!DOCTYPE html><title>Проверка браузера</title>',
        dedupSignature: null,
        rowsTotal: 0,
        httpStatus: 200,
        durationMs: 10,
        requestParams: ['from' => '1'],
        header: ['<!DOCTYPE html><title>Проверка браузера</title>'],
    ));

    expect($result->failure)->toBe(SourceFailureKind::LayoutDrift);
});

test('пустое тело при 2xx — отдельный вид отказа, блок пропускается', function (): void {
    Storage::fake('s3');
    $crawler = Crawler::factory()->for(Registry::factory()->eis()->create(['code' => 'fz44']))->create();
    $run = Run::factory()->for($crawler)->create();

    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, new ListingBatch(
        rows: [], rawBody: '', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 1,
        requestParams: ['from' => '1'], emptyBody: true,
    ));

    expect($result->failure)->toBe(SourceFailureKind::EmptyBody);
});
