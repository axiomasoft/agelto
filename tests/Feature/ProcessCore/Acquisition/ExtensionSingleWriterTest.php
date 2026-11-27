<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\CompletionStatus;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisExtensionPersister;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;
use Tests\Support\Procurement\SnapshotFactory;

test('complete empty extension clears prior common fields while listing metadata stays', function (): void {
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
})->group('platform-extension-ownership');

test('actual listing ingestor propagates extension failure and rolls back the whole block', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail]);
    ListingFixtures::bindLocal();
    $registry = Registry::factory()->eis()->create();
    $summaries = [];
    foreach (['00041', '00042'] as $value) {
        // Listings preserve the current extension; the second row's actual writer must fail
        // after the first canonical update, proving rollback of the entire publication.
        $p = app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($registry,
            ['registryNumber' => $value, 'etpNumber' => 'ETP-'.$value]));
        $summaries[] = new ProcurementSummary(
            ListingFixtures::summary($value)->identity, 'Updated '.$value, $p->source_stage,
            DateTimeImmutable::createFromInterface($p->published_at), new DateTimeImmutable('2026-01-11'),
            ContentHash::of('updated-'.$value), initialPrice: '200000.00', currency: 'RUB');
    }
    $f = ListingFixtures::run(registry: $registry);
    $lease = FinalizeFixtures::running($f['work']);
    $result = new StageSucceeded(new ListingResult(app(ListingStoreRepository::class)->task($f['input']), ListingFixtures::page($summaries)));
    $tables = ['prc_procurements', 'prc_revisions', 'prc_events', 'prc_eis_details', 'prc_crawl_blocks',
        'prc_process_work', 'prc_operation_intents', 'prc_process_transitions', 'prc_process_outbox'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }
    DB::unprepared("CREATE FUNCTION t42_fail_second() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.procurement_id=(SELECT id FROM prc_procurements WHERE source_identity_value='00042') THEN RAISE EXCEPTION 'extension rejected'; END IF; RETURN NEW; END $$");
    DB::unprepared('CREATE TRIGGER t42_failure BEFORE INSERT OR UPDATE ON prc_eis_details FOR EACH ROW EXECUTE FUNCTION t42_fail_second()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $result))->toThrow(QueryException::class);
        foreach ($tables as $table) {
            expect(DB::table($table)->get()->toJson())->toBe($before[$table], $table.' must roll back');
        }
    } finally {
        DB::unprepared('DROP TRIGGER t42_failure ON prc_eis_details; DROP FUNCTION t42_fail_second()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $result)->status)->toBe(CompletionStatus::Applied)
        ->and(Procurement::query()->where('title', 'like', 'Updated %')->count())->toBe(2)
        ->and(DB::table('prc_revisions')->count())->toBe(4)
        ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('state'))->toBe('succeeded');
})->group('platform-extension-ownership');

test('ошибка real extension writer откатывает canonical history и fenced cursor', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work();
    $p = $f['p'];
    $lease = FinalizeFixtures::running($f['work']);
    $part = DetailFixtures::snapshot($f['request'], 'attributes');
    $snapshot = new ProcurementSnapshot($part->identity, 'Updated', $part->sourceStage,
        [], [], [], [], $part->parts, new EisDetailExtension(etpNumber: 'ETP-2', hasCommonInfo: true));
    $result = new StageSucceeded(new DetailResult($snapshot, new DetailProgress,
        SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable));
    // Detail progress now belongs to the fenced Process work and snapshot checkpoint,
    // not the deleted crawler cursor. All publication and progress rows must roll back.
    $tables = ['prc_procurements', 'prc_revisions', 'prc_events', 'prc_eis_details',
        'prc_detail_snapshots', 'prc_process_work', 'prc_operation_intents', 'prc_process_transitions', 'prc_process_outbox'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }
    DB::unprepared("CREATE FUNCTION t42_fail_extension() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'extension rejected'; END $$");
    DB::unprepared('CREATE TRIGGER t42_failure BEFORE INSERT OR UPDATE ON prc_eis_details FOR EACH ROW EXECUTE FUNCTION t42_fail_extension()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $result))->toThrow(QueryException::class);
        foreach ($tables as $table) {
            expect(DB::table($table)->get()->toJson())->toBe($before[$table], $table.' must roll back');
        }
    } finally {
        DB::unprepared('DROP TRIGGER t42_failure ON prc_eis_details; DROP FUNCTION t42_fail_extension()');
    }
    expect(app(WorkFinalizer::class)->finish($lease, $result)->status)->toBe(CompletionStatus::Applied)
        ->and($p->refresh()->title)->toBe('Updated')->and($p->revision_count)->toBe(2)
        ->and(EisDetails::query()->where('procurement_id', $p->id)->value('etp_number'))->toBe('ETP-2')
        ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('state'))->toBe('succeeded')
        ->and(DB::table('prc_detail_snapshots')->where('procurement_id', $p->id)->where('completeness', 'complete')->count())->toBe(1);
})->group('platform-extension-ownership');

test('generic DTO не имеет полей ЕИС и extension требует caller transaction', function (): void {
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
})->group('platform-extension-ownership');
