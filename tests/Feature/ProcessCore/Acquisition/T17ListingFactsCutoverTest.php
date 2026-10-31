<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\ListingFacts;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Platforms\Eis\Csv\EisSummaryParser;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Acquisition\ListingStageCodec;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use Illuminate\Support\Carbon;
use Tests\Support\ProcessCore\ListingFixtures as F;

test('T17 listing cutover retains procedure bidding dates customer label and typed source metadata through publication', function (): void {
    F::bind();
    $f = F::run('2026-03-01');
    $summary = (new EisSummaryParser(new EisSourceClock('Europe/Moscow')))->parse([
        'Реестровый номер закупки' => '00042', 'Наименование закупки' => 'Поставка бумаги',
        'Этап закупки' => 'Подача заявок', 'Дата размещения' => '01.03.2026', 'Дата обновления' => '02.03.2026 10:15:00',
        'Начальная (максимальная) цена контракта' => '1 250 000,50', 'Валюта' => 'RUB',
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
        'Начало подачи заявок' => '01.03.2026', 'Окончание подачи заявок' => '15.03.2026',
        'Наименование Заказчика' => 'ГБУ Тест', 'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
    ]);
    expect($summary->facts)->toBeInstanceOf(ListingFacts::class);
    $codec = new ListingStageCodec(app(DetailValueCodec::class));
    $result = new ListingResult(app(ListingStoreRepository::class)->task($f['input']), F::page([$summary]));
    $encoded = $codec->encodeResult($result);
    $decoded = $codec->decodeResult($encoded);
    expect($codec->encodeResult($decoded)->json)->toBe($encoded->json);
    F::finish($f['work'], $f['input'], $decoded->page);
    $p = Procurement::query()->sole();
    expect($p->source_identity_value)->toBe('00042')
        ->and($p->procedure_type)->toBe('electronic_auction')->and($p->procedure_type_raw)->toBe('Электронный аукцион')
        ->and($p->bidding_starts_at?->toIso8601String())->toBe('2026-03-01T00:00:00+00:00')
        ->and($p->bidding_ends_at?->toIso8601String())->toBe('2026-03-15T00:00:00+00:00')
        ->and($p->attributes?->get('customer_name'))->toBe('ГБУ Тест')
        ->and(EisDetails::query()->findOrFail($p->id)->ikz)->toBe('243773601234512345100100001');
})->group('t17', 't27', 't42', 'p23');

test('T17 daily update precision denotes source-day instants while publication dates stay calendar dates', function (): void {
    $parser = new EisSummaryParser(new EisSourceClock('Europe/Moscow'));
    $row = ['Реестровый номер закупки' => '00045', 'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026', 'Начальная (максимальная) цена контракта' => '1'];
    $daily = $parser->parse($row);
    $exact = $parser->parse([...$row, 'Дата обновления' => '02.03.2026 00:00:00']);
    expect($daily->updatedAt->format(DATE_ATOM))->toBe('2026-03-01T21:00:00+00:00')
        ->and($daily->publishedAt->format(DATE_ATOM))->toBe('2026-03-01T00:00:00+00:00')
        ->and($daily->updatedAtDayPrecision)->toBeTrue()->and($exact->updatedAtDayPrecision)->toBeFalse()
        ->and($exact->updatedAt->format(DATE_ATOM))->toBe('2026-03-01T21:00:00+00:00');
})->group('t17', 't27', 'p23');

test('T17 source-day upper bound survives codec and fenced publication across DST without a fixed 24 hour assumption', function (string $date, string $day, int $hours): void {
    F::bind();
    $f = F::run($day);
    $summary = (new EisSummaryParser(new EisSourceClock('Europe/Berlin')))->parse([
        'Реестровый номер закупки' => '00049', 'Дата размещения' => $date, 'Дата обновления' => $date,
        'Начальная (максимальная) цена контракта' => '1',
    ]);
    expect($summary->updatedBefore->getTimestamp() - $summary->updatedAt->getTimestamp())->toBe($hours * 3600);
    $codec = new ListingStageCodec(app(DetailValueCodec::class));
    $payload = $codec->encodeResult(new ListingResult(app(ListingStoreRepository::class)->task($f['input']), F::page([$summary])));
    expect($payload->schemaVersion)->toBe(3);
    $decoded = $codec->decodeResult($payload);
    expect($decoded->page->summaries->items[0]->updatedBefore)->toEqual($summary->updatedBefore);
    F::finish($f['work'], $f['input'], $decoded->page);
    $p = Procurement::query()->sole();
    expect($p->source_updated_before->getTimestamp() - $p->source_updated_at->getTimestamp())->toBe($hours * 3600);
    $data = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
    $data['summaries'][0]['updatedBefore'] = $data['summaries'][0]['updated'];
    expect(fn () => $codec->decodeResult(new VersionedPayload(json_encode($data, JSON_THROW_ON_ERROR), 3)))
        ->toThrow(InvalidArgumentException::class);
    unset($data['summaries'][0]['updatedBefore']);
    expect(fn () => $codec->decodeResult(new VersionedPayload(json_encode($data, JSON_THROW_ON_ERROR), 3)))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $codec->decodeResult(new VersionedPayload($payload->json, 2)))->toThrow(InvalidArgumentException::class);
})->with(['short day' => ['29.03.2026', '2026-03-29', 23], 'long day' => ['25.10.2026', '2026-10-25', 25]])->group('t17', 't27', 'p23');

test('T42 listing metadata patches only declared extension fields and keeps the published common-info snapshot', function (): void {
    F::bind();
    $f = F::run();
    $registry = Registry::query()->findOrFail(app(ListingStoreRepository::class)->task($f['input'])->registryId);
    $base = F::summary('00043');
    $p = app(SnapshotFinalizer::class)->ingest(new ProcurementSnapshotData($registry->id, $base->identity,
        'Old title', $base->sourceStage, Carbon::parse('2025-12-31'), Carbon::parse('2025-12-31'),
        extension: new EisDetailExtension(etpName: 'Saved ETP', ikz: 'old', hasCommonInfo: true)));
    $summary = new ProcurementSummary($base->identity, $base->title, $base->sourceStage, $base->publishedAt, $base->updatedAt,
        $base->fingerprint, initialPrice: $base->initialPrice,
        facts: new ListingFacts(extension: new EisDetailExtension(ikz: 'new'), extensionFields: ['ikz']));
    F::finish($f['work'], $f['input'], F::page([$summary]));
    $saved = EisDetails::query()->findOrFail($p->id);
    $revision = app(ProcurementReadRepository::class)->findLatestRevision($p->id);
    expect($saved->ikz)->toBe('new')->and($saved->etp_name)->toBe('Saved ETP')
        ->and($revision?->snapshot['extension']['ikz'])->toBe('new')
        ->and($revision?->snapshot['extension']['etpName'])->toBe('Saved ETP')
        ->and($revision?->snapshot['extension']['hasCommonInfo'])->toBeTrue();
})->group('t17', 't27', 't42', 'p23');

test('T17 listing facts reject unknown extension fields mismatched types and malformed codec payloads', function (): void {
    $patch = new EisDetailExtension(ikz: 'value');
    expect(fn () => new ListingFacts(extension: $patch, extensionFields: ['unknown']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingFacts(extension: $patch, extensionFields: ['ikz', 'ikz']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingFacts(extension: $patch, extensionFields: []))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingFacts(extension: new EisDetailExtension, extensionFields: ['ikz']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingFacts(fields: ['unknown']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ListingFacts(fields: ['customerName', 'customerName']))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new ListingFacts(extension: $patch, extensionFields: ['ikz']))->mergeExtension(new stdClass))
        ->toThrow(InvalidArgumentException::class);
    F::bind();
    $f = F::run();
    $base = F::summary('00044');
    $s = new ProcurementSummary($base->identity, $base->title, $base->sourceStage, $base->publishedAt, $base->updatedAt,
        $base->fingerprint, facts: new ListingFacts(customerName: 'customer'));
    $codec = new ListingStageCodec(app(DetailValueCodec::class));
    $payload = $codec->encodeResult(new ListingResult(app(ListingStoreRepository::class)->task($f['input']), F::page([$s])));
    $data = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
    $facts = json_decode($data['summaries'][0]['facts'], true, flags: JSON_THROW_ON_ERROR);
    $facts['value']['unexpected'] = null;
    $data['summaries'][0]['facts'] = json_encode($facts, JSON_THROW_ON_ERROR);
    expect(fn () => $codec->decodeResult(new VersionedPayload(json_encode($data, JSON_THROW_ON_ERROR), $payload->schemaVersion)))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $codec->decodeResult(new VersionedPayload($payload->json, 1)))->toThrow(InvalidArgumentException::class);
})->group('t17', 't27', 't42', 'p23');

test('T17 missing listing columns preserve prior facts while explicitly empty columns clear only those facts', function (): void {
    F::bind();
    $f = F::run();
    $registry = Registry::query()->findOrFail(app(ListingStoreRepository::class)->task($f['input'])->registryId);
    $parser = new EisSummaryParser(new EisSourceClock('Europe/Moscow'));
    $row = ['Реестровый номер закупки' => '00047', 'Наименование закупки' => 'Current title', 'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '01.01.2026', 'Дата обновления' => '01.01.2026 10:00:00', 'Начальная (максимальная) цена контракта' => '1'];
    $summary = $parser->parse($row);
    expect($parser->parse([...$row, 'Наименование Заказчика' => '0'])->facts?->customerName)->toBe('0');
    expect(fn () => $parser->parse([...$row, 'Начало подачи заявок' => 'invalid date']))->toThrow(UnexpectedValueException::class);
    $p = app(SnapshotFinalizer::class)->ingest(new ProcurementSnapshotData($registry->id, $summary->identity,
        'Old title', $summary->sourceStage, Carbon::parse('2025-12-31'), Carbon::parse('2025-12-31'),
        procedureType: 'saved_type', procedureTypeRaw: 'Saved raw', biddingStartsAt: Carbon::parse('2025-12-31'),
        biddingEndsAt: Carbon::parse('2026-01-02'), attributes: ['customer_name' => 'Saved customer', 'diagnostic' => 'keep']));
    F::finish($f['work'], $f['input'], F::page([$summary]));
    $p->refresh();
    expect($p->procedure_type)->toBe('saved_type')->and($p->procedure_type_raw)->toBe('Saved raw')
        ->and($p->bidding_starts_at?->toDateString())->toBe('2025-12-31')
        ->and($p->bidding_ends_at?->toDateString())->toBe('2026-01-02')
        ->and($p->attributes?->get('customer_name'))->toBe('Saved customer');
    $next = F::next($f['crawl']) ?? throw new LogicException('Missing repeat pass');
    $empty = $parser->parse([...$row,
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => '',
        'Начало подачи заявок' => '', 'Окончание подачи заявок' => '', 'Наименование Заказчика' => '',
    ]);
    F::finish($next['work'], $next['input'], F::page([$empty]));
    $p->refresh();
    expect($p->procedure_type)->toBeNull()->and($p->procedure_type_raw)->toBeNull()
        ->and($p->bidding_starts_at)->toBeNull()->and($p->bidding_ends_at)->toBeNull()
        ->and($p->attributes?->get('customer_name'))->toBeNull()->and($p->attributes?->get('diagnostic'))->toBe('keep');
})->group('t17', 't27', 't42', 'p23');
