<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\MetricMeasurement;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\MeasurementState;
use App\Enums\Procurement\Process\RebuildStatus;
use App\Exceptions\Procurement\Process\ProjectionSchemaError;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Pulse\Cards\ProcessPlatformHealthCard;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Repositories\Procurement\Process\ProjectionRepository;
use App\Services\Procurement\Process\MetricCatalog;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\ProjectionRebuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\ProcessCore\Observability\ProjectionFixtures;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('t46', 'p33');

beforeEach(function (): void {
    ListingFixtures::bind();
    app(MetricProjector::class)->ensureStates();
    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
});

function p316EmptyDay(string $day, ContentHash $stream, AcquisitionKind $kind = AcquisitionKind::Fresh): void
{
    $run = ListingFixtures::run(from: $day, stream: $stream, registry: p316Registry(), kind: $kind);
    ListingFixtures::finish($run['work'], $run['input'], ListingFixtures::page());
    $next = ListingFixtures::next($run['crawl']) ?? throw new LogicException('Missing stable pass');
    ListingFixtures::finish($next['work'], $next['input'], ListingFixtures::page());
    expect(ListingFixtures::next($run['crawl']))->toBeNull();
}

function p316Registry(): Registry
{
    return Registry::query()->whereHas('platform', fn ($query) => $query->where('code', 'eis'))->first()
        ?? Registry::factory()->eis()->create();
}

test('T46 CLI и Pulse показывают no-data без успешной нулевой freshness и без запуска work', function (): void {
    $metrics = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('json'));
    expect($metrics->coveredThrough)->toBeNull()
        ->and($metrics->acquisitionControl)->toBeNull()
        ->and($metrics->acquisitionWatermarkLag->state)->toBe(MeasurementState::Unknown)
        ->and($metrics->acquisitionWatermarkLag->value)->toBeNull()
        ->and($metrics->listingClosedCompleteRatio->value)->toBeNull();
    test()->artisan('process:status', ['--platform' => 'json'])
        ->expectsOutputToContain('acquisition_watermark_lag_seconds=unknown (no_data)')
        ->expectsOutputToContain('acquisition_control=unknown')
        ->expectsOutputToContain('listing_closed_complete_ratio=unknown (no_closed_partitions)')
        ->assertSuccessful();
    test()->artisan('process:status', ['--platform' => 'not_registered'])->assertExitCode(2);
    Livewire::withoutLazyLoading()->test(ProcessPlatformHealthCard::class)
        ->assertSee('json')->assertSee('unknown (no_data)')->assertSee('unknown (no_closed_partitions)');
    expect(DB::table('prc_process_work')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->count())->toBe(0);
});

test('T46 real complete-empty producer продвигает contiguous watermark без source publications', function (): void {
    $stream = ContentHash::of('fresh');
    p316EmptyDay('2026-01-01', $stream);
    p316EmptyDay('2026-01-02', $stream);
    $snapshot = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('eis'));
    expect($snapshot->coveredThrough)->toBe('2026-01-02')
        ->and($snapshot->acquisitionWatermarkLag->state)->toBe(MeasurementState::Unknown)
        ->and($snapshot->acquisitionWatermarkLag->reason)->toBe('coverage_day_precision')
        ->and($snapshot->listingClosedCompleteRatio->value)->toBe(1)
        ->and($snapshot->closedPartitions)->toBe(2)
        ->and(DB::table('prc_procurements')->count())->toBe(0);
});

test('T46 gap удерживает watermark archive не заменяет fresh и другой stream не скрывает отсутствие coverage', function (): void {
    $stream = ContentHash::of('fresh');
    p316EmptyDay('2026-01-01', $stream);
    $gap = ListingFixtures::run(from: '2026-01-02', stream: $stream, registry: p316Registry(), kind: AcquisitionKind::Fresh);
    p316EmptyDay('2026-01-03', $stream);
    p316EmptyDay('2026-01-04', ContentHash::of('archive'), AcquisitionKind::Archive);
    $reader = app(PlatformMetricsReadRepository::class);
    expect($reader->read(new PlatformCode('eis'))->coveredThrough)->toBe('2026-01-01');
    ListingFixtures::finish($gap['work'], $gap['input'], ListingFixtures::page());
    $next = ListingFixtures::next($gap['crawl']) ?? throw new LogicException('Missing pass');
    ListingFixtures::finish($next['work'], $next['input'], ListingFixtures::page());
    expect($reader->read(new PlatformCode('eis'))->coveredThrough)->toBe('2026-01-03');
    ListingFixtures::run(from: '2026-01-01', stream: ContentHash::of('uncovered'), registry: p316Registry(), kind: AcquisitionKind::Fresh);
    $snapshot = $reader->read(new PlatformCode('eis'));
    expect($snapshot->coveredThrough)->toBeNull()
        ->and($snapshot->acquisitionWatermarkLag->reason)->toBe('coverage_gap')
        ->and($snapshot->collectingPartitions)->toBe(1);
});

test('T46 consumer читает replayed active totals дубликаты не удваивают labels остаются bounded', function (): void {
    $platformId = p316Registry()->platform_id;
    ['work' => $work] = ProjectionFixtures::workInRun('platform:'.$platformId);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['platform_id' => $platformId]);
    $event = ProjectionFixtures::transition($work, 'deferred', 'rate_limit', 'running');
    app(MetricProjector::class)->apply($event);
    app(MetricProjector::class)->apply($event);
    $reader = app(PlatformMetricsReadRepository::class);
    $before = $reader->read(new PlatformCode('eis'));
    $deferred = array_values(array_filter($before->counters, fn ($counter): bool => $counter->metricKey === 'stage_deferred_total'));
    expect($deferred)->toHaveCount(1)->and($deferred[0]->value)->toBe(1);
    expect(fn () => MetricCatalog::dimensionKey('stage_deferred_total', [
        'platform' => 'https://secret.example/request/123', 'stage' => 'listing', 'reason' => 'rate_limit',
    ]))->toThrow(ProjectionSchemaError::class);
    expect(fn () => MetricCatalog::dimensionKey('stage_deferred_total', [
        'platform' => 'eis', 'stage' => 'listing', 'reason' => 'exception:123',
    ]))->toThrow(ProjectionSchemaError::class);
    $rebuilt = app(ProjectionRebuilder::class)->rebuild('work_transitions');
    expect($rebuilt->status)->toBe(RebuildStatus::Rebuilt);
    $snapshot = $reader->read(new PlatformCode('eis'));
    expect($snapshot->projection?->activeGeneration)->toBe(2)
        ->and($snapshot->projectionLag->value)->toBe(0)
        ->and(array_values(array_filter($snapshot->counters, fn ($counter): bool => $counter->metricKey === 'stage_deferred_total')))->toEqual($deferred);
    test()->artisan('process:status', ['--platform' => 'eis'])
        ->expectsOutputToContain('stage_deferred_total v1;platform=eis;stage=listing;reason=rate_limit 1')
        ->expectsOutputToContain('generation=2')->assertSuccessful();
    expect($reader->read(new PlatformCode('json'))->counters)->toBe([]);
});

test('T46 incomplete входит в denominator closed partitions а collecting показан отдельно', function (): void {
    p316EmptyDay('2026-01-01', ContentHash::of('complete'));
    $run = ListingFixtures::run(from: '2026-01-02', registry: p316Registry(), kind: AcquisitionKind::Fresh);
    $work = $run['work'];
    $input = $run['input'];
    foreach (['A', 'B', 'C'] as $title) {
        ListingFixtures::finish($work, $input, ListingFixtures::page([ListingFixtures::summary('00042', $title)]));
        $next = ListingFixtures::next($run['crawl']);
        if ($next !== null) {
            $work = $next['work'];
            $input = $next['input'];
        }
    }
    expect($next)->toBeNull();
    ListingFixtures::run(from: '2026-01-03', registry: p316Registry(), kind: AcquisitionKind::Fresh);
    $snapshot = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('eis'));
    expect($snapshot->closedPartitions)->toBe(2)
        ->and($snapshot->listingClosedCompleteRatio->value)->toBe(0.5)
        ->and($snapshot->collectingPartitions)->toBe(1);
});

test('T46 authoritative due age и pauses отделены от исторических counters и maintenance', function (): void {
    $procurement = Procurement::factory()->create();
    DB::table('prc_procurements')->where('id', $procurement->id)
        ->update(['next_status_due_at' => DB::raw("clock_timestamp()-interval '2 hours'")]);
    $platformId = $procurement->platform_id;
    ['work' => $work] = ProjectionFixtures::workInRun('platform:'.$platformId);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['platform_id' => $platformId]);
    $event = ProjectionFixtures::transition($work, 'deferred', 'rate_limit', 'running');
    DB::table('prc_process_transitions')->where('event_id', $event->value)
        ->update(['occurred_at' => DB::raw("clock_timestamp()-interval '90 seconds'")]);
    $reader = app(PlatformMetricsReadRepository::class);
    $before = $reader->read(new PlatformCode('eis'));
    expect($before->projectionLag->value)->toBeGreaterThanOrEqual(90)
        ->and($before->statusRefreshDueLag->value)->toBeGreaterThanOrEqual(7200)
        ->and($before->workCounts[0]->state->value)->toBe('ready');
    // Reverse/old events do not turn the current ready authority into a deferred gauge.
    ProjectionFixtures::applyAll();
    $token = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(ProjectionRepository::class)->enterMaintenance('work_transitions', $token));
    $snapshot = $reader->read(new PlatformCode('eis'));
    expect($snapshot->projection?->mode->value)->toBe('maintenance')
        ->and($snapshot->counters)->not->toBe([])
        ->and($snapshot->projectionLag->value)->toBe(0)
        ->and($snapshot->workCounts[0]->state->value)->toBe('ready');
    DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->update(['desired_mode' => 'paused']);
    $paused = $reader->read(new PlatformCode('eis'));
    expect($paused->pausedScopes)->toBe(['acquisition'])
        ->and($paused->statusRefreshDueLag->state)->toBe(MeasurementState::NotApplicable)
        ->and($paused->statusRefreshDueLag->reason)->toBe('paused');
    test()->artisan('process:status', ['--platform' => 'eis'])
        ->expectsOutputToContain('acquisition_control=paused: acquisition')
        ->expectsOutputToContain('projection_mode=maintenance')
        ->expectsOutputToContain('work_ready stage=listing reason=none count=1')->assertSuccessful();
});

test('T46 measurement invariant запрещает успешный null и фиктивный zero для unknown', function (): void {
    expect(fn () => new MetricMeasurement(MeasurementState::Known, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MetricMeasurement(MeasurementState::Unknown, 0, 'no_data'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MetricMeasurement(MeasurementState::Known, -1))->toThrow(InvalidArgumentException::class);
});
