<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\StatusRefreshHandler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\Procurement\SnapshotFactory;

pest()->group('t17', 't12', 'p23');

test('T17 unchanged status allocates two distinct cycles and fetches twice with closed required runs', function (): void {
    F::bind();
    $f = F::work();
    $snapshot = F::snapshot($f['request'], 'attributes');
    expect(ProcurementResource::getEloquentQuery()->findOrFail($f['p']->id)->detail_fetched_at)->toBeNull();
    $lease = FinalizeFixtures::running($f['work']);
    app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new DetailResult($snapshot, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    $publishedAt = DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->value('created_at');
    expect(ProcurementResource::getEloquentQuery()->findOrFail($f['p']->id)->detail_fetched_at?->toDateTimeString())
        ->toBe(Carbon::parse($publishedAt)->toDateTimeString());
    $source = new DetailFixtureSource([new DetailFetched($snapshot), new DetailFetched($snapshot)], ['attributes']);
    $handler = new StatusRefreshHandler(app(RefreshRepository::class), app(DetailSnapshotRepository::class), $source, new FetchOutcomeBridge);
    $ids = [];
    for ($i = 1; $i <= 2; $i++) {
        DB::update('UPDATE prc_procurements SET next_status_due_at=clock_timestamp() WHERE id=?', [$f['p']->id]);
        $work = app(RefreshRepository::class)->scheduleStatus($f['p']->id) ?? throw new LogicException('Cycle not allocated');
        $ids[] = $work->value->value;
        expect(app(RefreshRepository::class)->scheduleStatus($f['p']->id))->toBeNull();
        $input = F::input($work);
        expect($input)->toBeInstanceOf(StatusRefreshInput::class);
        $lease = FinalizeFixtures::running($work);
        $out = $handler->execute($input, app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $out);
    }
    expect(array_unique($ids))->toHaveCount(2)->and($source->calls)->toBe(['attributes', 'attributes'])
        ->and(DB::table('prc_status_refreshes')->count())->toBe(2)
        ->and(DB::table('prc_procurements')->value('status_cycle_seq'))->toBe(2)
        ->and(DB::table('prc_process_runs')->where('discovery_outcome', 'complete')->count())->toBe(2)
        ->and(DB::table('prc_process_run_work')->where('accounting_role', 'required')->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('stage', 'status_refresh')->where('state', 'succeeded')->count())->toBe(2);
    expect(ProcurementResource::getEloquentQuery()->findOrFail($f['p']->id)->detail_fetched_at?->toDateTimeString())
        ->toBe(Carbon::parse($publishedAt)->toDateTimeString());
});

test('T17 status received before a newer head is history only and requests reconciliation', function (): void {
    F::bind();
    $f = F::work();
    $snapshot = F::snapshot($f['request'], 'attributes');
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), new StageSucceeded(new DetailResult($snapshot, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    $work = app(RefreshRepository::class)->scheduleStatus($f['p']->id) ?? throw new LogicException('Missing cycle');
    $input = F::input($work);
    $lease = FinalizeFixtures::running($work);
    $handler = new StatusRefreshHandler(app(RefreshRepository::class), app(DetailSnapshotRepository::class), new DetailFixtureSource([new DetailFetched($snapshot)], ['attributes']), new FetchOutcomeBridge);
    $out = $handler->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(SnapshotFinalizer::class)->ingest(SnapshotFactory::make($f['p']->registry ?? throw new LogicException('Missing registry'), ['registryNumber' => $f['p']->source_identity_value, 'title' => 'New head', 'sourceStage' => 'Работа комиссии']));
    app(WorkFinalizer::class)->finish($lease, $out);
    expect($f['p']->refresh()->title)->toBe('New head')->and($f['p']->source_stage)->toBe('Работа комиссии')
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:status')->count())->toBe(1)
        ->and(DB::table('prc_procurements')->whereRaw('next_status_due_at<=clock_timestamp()')->count())->toBe(1);
});

test('T12 due detail with unchanged control retains supplier results and creates a new intent', function (): void {
    F::bind();
    $f = F::work();
    $s = F::snapshot($f['request'], 'attributes');
    $results = new SupplierResultsData(new ProtocolData('failed', 'protocol-1', null, null), [], null);
    $full = new ProcurementSnapshot($s->identity, $s->title, $s->sourceStage, [], [], [], [], [...$s->parts, ...F::snapshot($f['request'], 'results')->parts], results: $results);
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), new StageSucceeded(new DetailResult($full, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    DB::update('UPDATE prc_operation_intents SET next_due_at=clock_timestamp() WHERE id=?', [$f['input']->intentId->value]);
    $work = app(RefreshRepository::class)->scheduleDetail($f['p']->id) ?? throw new LogicException('Missing detail cycle');
    expect($work->value->value)->not->toBe($f['work']->value->value)->and(app(RefreshRepository::class)->scheduleDetail($f['p']->id))->toBeNull();
    $input = F::input($work);
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($work), new StageSucceeded(new DetailResult($full, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    expect(DB::table('prc_protocols')->where('procurement_id', $f['p']->id)->count())->toBe(1)
        ->and(DB::table('prc_procurements')->value('detail_cycle_seq'))->toBe(1)
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(2);
});
