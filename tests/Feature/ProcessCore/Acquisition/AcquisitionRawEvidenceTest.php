<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionDiagnostic;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionArtifacts;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionHandler;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver as ProxyResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Platforms\Eis\EisDetailFixtures;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;

beforeEach(function (): void {
    PermitFixtures::clean();
    $this->rawBefore = RawArtifact::query()->pluck('id')->all();
    $this->platformBefore = Platform::query()->where('code', 'eis')->value('id');
    $this->registryBefore = Registry::query()->where('platform_id', $this->platformBefore)->where('code', 'fz44')->value('id');
    $this->wire = new WireFixture;
    $this->fixture = new AcquisitionWireFixture($this->wire);
    Storage::fake('s3');
});

afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
    RawArtifact::query()->whereNotIn('id', $this->rawBefore)->delete();
    $platform = $this->fixture->registry->platform;
    if ($this->registryBefore === null) {
        $this->fixture->registry->delete();
    }
    if ($this->platformBefore === null) {
        $platform->delete();
    }
});

test('actual acquisition retains the last invalid CSV response bytes under its physical identity', function (string $body, int $status): void {
    foreach (range(1, $status === 503 ? 3 : 1) as $unused) {
        $this->wire->respond('/csv', $body, $status, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
    $this->fixture->fetchWork($this->fixture->listingRequest());
    $raw = RawArtifact::query()->whereNotIn('id', $this->rawBefore)->sole();
    $request = PackageNetworkFacts::attempts()->orderByDesc('request_seq')->first();
    expect($raw->path)->toStartWith('raw/acquisition/'.$request->request_id.'/')
        ->and(Storage::disk('s3')->get($raw->path))->toBe($body)
        ->and(PackageNetworkFacts::attempts()->count())->toBe($status === 503 ? 3 : 1)
        ->and(DB::table('prc_procurements')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(0);
})->with([
    'html 200' => ['<html>Access denied</html>', 200],
    'http 403' => ['Forbidden', 403],
    'http 503 exhausted' => ['Unavailable', 503],
    'repeated empty' => ['', 200],
    'invalid header' => ['invalid;header', 200],
]);

test('an intermediate HTTP error followed by success retains only final decoded input evidence', function (): void {
    $header = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-empty.csv'));
    $this->wire->respond('/csv', 'intermediate error', 503, ['Content-Type' => 'text/csv; charset=UTF-8']);
    $this->wire->respond('/csv', mb_convert_encoding($header, 'Windows-1251', 'UTF-8'),
        200, ['Content-Type' => 'text/csv; charset=Windows-1251']);
    $this->fixture->fetchWork($this->fixture->listingRequest());
    $raw = RawArtifact::query()->whereNotIn('id', $this->rawBefore)->sole();
    expect(Storage::disk('s3')->get($raw->path))->toBe($header)
        ->and(PackageNetworkFacts::attempts()->count())->toBe(2)
        ->and($this->wire->receipts())->toHaveCount(2);
});

test('a final transport failure cannot attach a previous HTTP body to its physical request', function (): void {
    $last = LedgerFixtures::uuid();
    app(AcquisitionArtifacts::class)->capture(new TransportDeferred(
        NetworkDeferralReason::AttemptsExhausted, new RetryAt(new DateTimeImmutable('+1 minute')), lastRequestId: $last), TrafficClass::FreshListing);
    expect(RawArtifact::query()->whereNotIn('id', $this->rawBefore)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('raw acquisition evidence storage failure is a dependency and cannot confirm full adapter success', function (): void {
    $this->wire->respond('/csv', file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-empty.csv')),
        200, ['Content-Type' => 'text/csv; charset=UTF-8']);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
    try {
        $this->fixture->fetch($this->fixture->listingRequest());
        $this->fail('Missing mandatory raw evidence must not be successful input validation.');
    } catch (DependencyUnavailable $failure) {
        expect($failure->dependency)->toBe('storage');
    }
    expect($this->wire->receipts())->toHaveCount(1)
        ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->count())->toBe(0)
        ->and(DB::table('prc_outbound_operations')->where('state', '<>', 'closed')->count())->toBe(0);
});

test('actual EIS detail raw retention remains optional and gzip evidence uses physical request identities', function (bool $enabled): void {
    config(['acquisition.raw_html_retention_enabled' => $enabled, 'acquisition.raw_html_gzip' => true]);
    $bodies = EisDetailFixtures::bodies();
    foreach ($bodies as $tab => $body) {
        $this->wire->respond('/'.$tab, $body, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }
    $result = $this->fixture->fetch(EisDetailFixtures::request());
    expect($result->value)->toBeInstanceOf(DetailFetched::class)
        ->and($this->wire->receipts())->toHaveCount(4)
        ->and(PackageNetworkFacts::permits()->where('state', 'released')->count())->toBe(4)
        ->and(array_unique(PackageNetworkFacts::attempts()->pluck('route_id')->all()))->toHaveCount(1);
    $raw = RawArtifact::query()->whereNotIn('id', $this->rawBefore)->get();
    expect($raw)->toHaveCount($enabled ? 4 : 0);
    $decoded = [];
    foreach ($raw as $artifact) {
        expect($artifact->path)->toEndWith('.txt.gz');
        $decoded[] = gzdecode(Storage::disk('s3')->get($artifact->path));
    }
    if ($enabled) {
        expect($decoded)->toEqualCanonicalizing(array_values($bodies));
    } else {
        expect(Storage::disk('s3')->allFiles())->toBe([]);
    }
})->with(['enabled' => [true], 'disabled' => [false]]);

test('actual Process detail identity rejection persists matching assessed bytes before item finalization even with raw retention disabled', function (): void {
    config(['acquisition.raw_html_retention_enabled' => false]);
    DetailFixtures::bind();
    $f = DetailFixtures::work(registry: $this->fixture->registry, value: '0338200009125000001', adapterVersion: '1');
    DB::table('prc_process_work')->where('id', $f['work']->value->value)->update(['platform_id' => $f['p']->platform_id]);
    $profile = AcquisitionProfileFixtures::create();
    try {
        $profile->update(['route_profile_id' => $this->fixture->routeProfile->value]);
        $launch = app(AcquisitionProfiles::class)->prepare($profile,
            AcquisitionKind::Fresh, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01'));
        $run = DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('origin_run_id');
        DB::table('prc_process_runs')->where('id', $run)->update(['configuration_snapshot' => $launch->configuration->json,
            'configuration_schema_version' => $launch->configuration->schemaVersion]);
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $body = str_replace('0338200009125000001', '9999999999999999999', EisDetailFixtures::bodies()['common-info']);
        $this->wire->respond('/common-info', $body, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
        $lease = FinalizeFixtures::running($f['work']);
        $context = app(ExecutionContextFactory::class)->open($lease);
        $handler = app()->makeWith(AcquisitionHandler::class,
            ['stage' => StageCode::Detail, 'urls' => $this->wire->policy()]);
        $outcome = $handler->execute($f['input'], $context);
        expect($outcome)->toBeInstanceOf(StageFailedItem::class,
            $outcome instanceof StageBlocked ? $outcome->reason->value : '')
            ->and(Storage::disk($outcome->evidence->disk)->get($outcome->evidence->key))->toBe($body)
            ->and($outcome->evidence->sha256->hex)->toBe(hash('sha256', $body));
        app(WorkFinalizer::class)->finish($context->lease(), $outcome);
        expect(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('item_failures'))->toBe(1)
            ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('state'))->toBe('deferred')
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1)
            ->and(DB::table('prc_eis_details')->count())->toBe(0);

        app()->instance(EisCardRoutes::class, new EisCardRoutes([]));
        $retry = app(ExecutionContextFactory::class)->open(FinalizeFixtures::rerun($f['work']));
        $unmapped = $handler->execute($f['input'], $retry);
        if (! $unmapped instanceof StageFailedItem) {
            throw new LogicException('Ожидалось отклонение неизвестной процедуры');
        }
        $proof = Storage::disk($unmapped->evidence->disk)->get($unmapped->evidence->key);
        expect($unmapped->reason)->toBe(ItemFailureReason::InvalidInput)
            ->and($unmapped->evidence->key)->toStartWith('raw/acquisition/input/')
            ->and($unmapped->evidence->sha256->hex)->toBe(ContentHash::of($proof ?? '')->hex)
            ->and(json_decode($proof ?? '', true, flags: JSON_THROW_ON_ERROR)['reason'])->toBe('unmapped_procedure');
        app(WorkFinalizer::class)->finish($retry->lease(), $unmapped);
        expect(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('item_failures'))->toBe(2)
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1);
    } finally {
        // This wire test commits fixture rows; remove only its own purchase after ledger cleanup.
        PermitFixtures::clean();
        DB::table('prc_events')->where('procurement_id', $f['p']->id)->delete();
        DB::table('prc_revisions')->where('procurement_id', $f['p']->id)->delete();
        DB::table('prc_detail_snapshots')->where('procurement_id', $f['p']->id)->delete();
        $f['p']->delete();
        $profile->delete();
    }
});

test('actual rejection evidence storage failure after HTTP success is a dependency and never an adapter handler fault', function (): void {
    config(['acquisition.raw_html_retention_enabled' => false]);
    DetailFixtures::bind();
    $f = DetailFixtures::work(registry: $this->fixture->registry, value: '0338200009125000001', adapterVersion: '1');
    DB::table('prc_process_work')->where('id', $f['work']->value->value)->update(['platform_id' => $f['p']->platform_id]);
    $profile = AcquisitionProfileFixtures::create();
    try {
        $profile->update(['route_profile_id' => $this->fixture->routeProfile->value]);
        $launch = app(AcquisitionProfiles::class)->prepare($profile,
            AcquisitionKind::Fresh, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-01'));
        $run = DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('origin_run_id');
        DB::table('prc_process_runs')->where('id', $run)->update(['configuration_snapshot' => $launch->configuration->json,
            'configuration_schema_version' => $launch->configuration->schemaVersion]);
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $body = str_replace('0338200009125000001', '9999999999999999999', EisDetailFixtures::bodies()['common-info']);
        $this->wire->respond('/common-info', $body, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
        $lease = FinalizeFixtures::running($f['work']);
        $context = app(ExecutionContextFactory::class)->open($lease);
        $handler = app()->makeWith(AcquisitionHandler::class,
            ['stage' => StageCode::Detail, 'urls' => $this->wire->policy()]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
        expect(fn () => $handler->execute($f['input'], $context))->toThrow(DependencyUnavailable::class)
            ->and(PackageNetworkFacts::attempts()->sole()->status)->toBe(200)
            ->and(DB::table('prc_observations')->where('reason_code', 'acquisition.handler_fault')->count())->toBe(0)
            ->and(DB::table('prc_adapter_health')->where('capability', 'detail')->value('state'))->toBe('healthy')
            ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('state'))->toBe('running')
            ->and(DB::table('prc_process_work')->where('id', $f['work']->value->value)->value('item_failures'))->toBe(0)
            ->and(DB::table('prc_eis_details')->count())->toBe(0);
    } finally {
        // This wire test commits fixture rows; remove only its own purchase after ledger cleanup.
        PermitFixtures::clean();
        DB::table('prc_events')->where('procurement_id', $f['p']->id)->delete();
        DB::table('prc_revisions')->where('procurement_id', $f['p']->id)->delete();
        DB::table('prc_detail_snapshots')->where('procurement_id', $f['p']->id)->delete();
        $f['p']->delete();
        $profile->delete();
    }
});

test('actual acquisition response byte limit aborts HTTP without parser publication or a false empty proof', function (): void {
    config(['platforms.eis.endpoints.listing.profile.max_entity_bytes' => 64]);
    $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
    ProxymaFixtures::resetAuthority();
    app(SourceCatalogSync::class)->sync($definition->sourceTargets);
    HistoricalNetworkFixtures::synchronize();
    ProxymaCallerFixtures::install($this->wire);
    $this->wire->respond('/csv', str_repeat('x', 1024), headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
    $result = app(AcquisitionDiagnostic::class)->fetch($definition, $this->fixture->registry->platform_id,
        $this->fixture->listingRequest(), app(ProxyResolver::class)->profile($this->fixture->routeProfile),
        ContentHash::of('byte-limit'), $this->wire->policy());
    expect($result->receipt)->toBeNull()->and($this->wire->receipts())->toHaveCount(1)
        ->and(PackageNetworkFacts::attempts()->sole()->outcome)->toBe('byte_limit')
        ->and(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
});

test('actual EIS card timeout retains numeric HTTP and byte phases without a raw exception or canonical mutation', function (string $path, ?int $status, int $bytes): void {
    config(['platforms.eis.endpoints.card.profile.total_seconds' => 1, 'platforms.eis.endpoints.card.profile.connect_seconds' => 1,
        'platforms.eis.endpoints.card.profile.low_speed_seconds' => 1]);
    $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
    ProxymaFixtures::resetAuthority();
    app(SourceCatalogSync::class)->sync($definition->sourceTargets);
    HistoricalNetworkFixtures::synchronize();
    ProxymaCallerFixtures::install($this->wire);
    app()->instance(EisCardRoutes::class, new EisCardRoutes(['ea20' => [
        'common-info' => $this->wire->url($path.'?regNumber={regNumber}', true)->reveal(),
        'documents' => $this->wire->url('/documents?regNumber={regNumber}', true)->reveal(), 'event-journal' => null, 'supplier-results' => null,
    ]]));
    $result = app(AcquisitionDiagnostic::class)->fetch($definition, $this->fixture->registry->platform_id,
        EisDetailFixtures::request(), app(ProxyResolver::class)->profile($this->fixture->routeProfile), ContentHash::of('card-timeout'), $this->wire->policy());
    $attempt = PackageNetworkFacts::attempts()->sole();
    expect($result->value)->toBeInstanceOf(FetchDeferred::class)
        ->and($attempt->status)->toBe($status)->and($attempt->bytes)->toBe($bytes)
        ->and($attempt->duration_ms)->toBeGreaterThanOrEqual(0)->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->outcome)->toBe('timeout')->and(DB::table('prc_procurements')->count())->toBe(0)
        ->and(RawArtifact::query()->whereNotIn('id', $this->rawBefore)->count())->toBe(0);
})->with(['without headers' => ['/stall', null, 0], 'partial body' => ['/body-partial-stall', 200, 12415]]);
