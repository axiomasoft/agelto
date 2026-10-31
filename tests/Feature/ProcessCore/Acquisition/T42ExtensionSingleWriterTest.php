<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\EisExtensionPersister;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\CsvBlockIngestor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\Procurement\SnapshotFactory;

test('T42 complete empty extension clears prior common fields while listing metadata stays', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = app(SnapshotFinalizer::class)
        ->ingest(SnapshotFactory::make($registry, ['etpNumber' => 'ETP-42']));
    DB::transaction(function () use ($procurement): void {
        $writer = app(EisExtensionPersister::class);
        $writer->persist($procurement->id, new EisDetailExtension(etpName: 'Old', hasCommonInfo: true));
        $writer->persist($procurement->id, new EisDetailExtension(hasCommonInfo: true));
    });
    $details = EisDetails::query()->findOrFail($procurement->id);
    expect($details->etp_name)->toBeNull()->and($details->etp_number)->toBe('ETP-42');
})->group('t42', 'p23');

test('T42 actual listing ingestor propagates extension failure and rolls back the whole block', function (): void {
    $registry = Registry::factory()->eis()->create();
    $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
    $manager = app(CrawlerRunManager::class);
    $run = $manager->startOrResume($crawler, RunPurpose::Profile, null);
    expect($manager->acquireExecutionLease($run))->toBeTrue();
    $rows = [];
    foreach (['00041', '00042'] as $value) {
        $rows[] = [
            'Реестровый номер закупки' => $value, 'Наименование закупки' => 'Fixture',
            'Способ определения поставщика' => 'Электронный аукцион', 'Этап закупки' => 'Подача заявок',
            'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
            'НМЦК' => '1 250 000,50', 'Валюта' => 'RUB', 'ОКПД2' => '26.20.11.110',
            'Дата размещения' => '01.03.2026', 'Дата обновления' => '02.03.2026 10:15:00',
            'Начало подачи заявок' => '01.03.2026', 'Окончание подачи заявок' => '15.03.2026',
            'Наименование заказчика' => 'Fixture customer',
        ];
    }
    $batch = new ListingBatch(rows: $rows, rawBody: 'already staged',
        dedupSignature: '00041,00042', rowsTotal: 2, httpStatus: 200, durationMs: 1,
        requestParams: ['from' => '1', 'to' => '500']);
    DB::unprepared("CREATE FUNCTION pg_temp.p214_block_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
        IF EXISTS(SELECT 1 FROM prc_procurements WHERE id=NEW.procurement_id AND source_identity_value='00042') THEN
            RAISE EXCEPTION 'extension block failure'; END IF; RETURN NEW; END $$");
    DB::unprepared('CREATE TRIGGER p214_block_failure BEFORE INSERT ON prc_eis_details FOR EACH ROW EXECUTE FUNCTION pg_temp.p214_block_failure()');
    try {
        expect(fn () => $manager->withinExecutionLease($run, function () use ($manager, $run, $crawler, $batch): void {
            $manager->updateCursor($run, new ListingCursor(blockFrom: 501));
            app(CsvBlockIngestor::class)->ingest($crawler, $run, $batch, artifactRef: 'already-staged');
        }))->toThrow(QueryException::class, 'extension block failure');
        expect(Procurement::query()->count())->toBe(0)
            ->and(DB::table('prc_revisions')->count())->toBe(0)
            ->and(DB::table('prc_events')->count())->toBe(0)
            ->and(EisDetails::query()->count())->toBe(0)
            ->and($run->fresh()->cursor->get('blockFrom'))->toBe(1);
    } finally {
        DB::unprepared('DROP TRIGGER p214_block_failure ON prc_eis_details');
    }
})->group('t42', 'p23');

test('T42 ошибка real extension writer откатывает canonical history и fenced cursor', function (): void {
    $crawler = Crawler::factory()->create();
    $registry = $crawler->registry;
    $manager = app(CrawlerRunManager::class);
    $run = $manager->startOrResume($crawler, RunPurpose::Profile, null);
    expect($manager->acquireExecutionLease($run))->toBeTrue();
    $snapshot = SnapshotFactory::make($registry, ['etpNumber' => 'ETP-0001']);
    $procurement = app(SnapshotFinalizer::class)->ingest($snapshot, $run->id);
    $events = DB::table('prc_events')->where('procurement_id', $procurement->id)->count();
    DB::unprepared("CREATE FUNCTION pg_temp.reject_extension() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture extension failure'; END $$");
    DB::unprepared('CREATE TRIGGER reject_extension BEFORE INSERT OR UPDATE ON prc_eis_details FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_extension()');
    $next = SnapshotFactory::make($registry, ['etpNumber' => 'ETP-0002', 'title' => 'Updated', 'initialPrice' => '200000.00']);
    expect(fn () => $manager->withinExecutionLease($run, function () use ($manager, $run, $next): void {
        $manager->updateCursor($run, new ListingCursor(blockFrom: 501));
        app(SnapshotFinalizer::class)->ingest($next, $run->id);
    }))->toThrow(QueryException::class);
    expect(Procurement::query()->count())->toBe(1)
        ->and($procurement->refresh()->title)->toBe($snapshot->title)
        ->and($procurement->revision_count)->toBe(1)
        ->and(DB::table('prc_revisions')->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(DB::table('prc_events')->where('procurement_id', $procurement->id)->count())->toBe($events)
        ->and(EisDetails::query()->findOrFail($procurement->id)->etp_number)->toBe('ETP-0001')
        ->and($run->refresh()->cursor->get('blockFrom'))->toBe(1);
    DB::unprepared('DROP TRIGGER reject_extension ON prc_eis_details');
    $manager->withinExecutionLease($run, function () use ($manager, $run, $next): void {
        app(SnapshotFinalizer::class)->ingest($next, $run->id);
        $manager->updateCursor($run, new ListingCursor(blockFrom: 501));
    });
    expect($procurement->refresh()->revision_count)->toBe(2)
        ->and(EisDetails::query()->findOrFail($procurement->id)->etp_number)->toBe('ETP-0002')
        ->and($run->refresh()->cursor->get('blockFrom'))->toBe(501);
})->group('t42', 'p23');

test('T42 generic DTO не имеет полей ЕИС и extension требует caller transaction', function (): void {
    foreach ([ProcurementSnapshotData::class, CommonInfoData::class] as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            expect(preg_match('/ikz|etp|registryNumber|registryGuid/i', $property->name))->toBe(0);
        }
    }
    $result = ConcurrentTransactions::execute(<<<'PHP'
try {
    app(\App\Services\Platforms\Eis\EisExtensionPersister::class)->persist(1, new \App\Dto\Platforms\Eis\EisDetailExtension(etpNumber: 'forbidden'));
    return ['rejected' => false];
} catch (\LogicException) {
    return ['rejected' => true];
}
PHP);
    expect($result['rejected'])->toBeTrue();
})->group('t42', 'p23');
