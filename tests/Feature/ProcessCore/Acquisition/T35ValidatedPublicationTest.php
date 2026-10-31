<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ValidatedAcquisitionResult;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\ListingFinalizer;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Crawler\ValidatedAcquisitionFinalizer;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\AcquisitionValidation;
use App\Services\Procurement\Acquisition\ListingStageCodec;
use App\Services\Procurement\Acquisition\ValidatedAcquisitionCodec;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\ListingFixtures;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});
pest()->group('t06', 't07', 't35', 'p23');

test('acquisition publishes only a current complete validation receipt and uses a separate bounded canary', function (bool $canary, bool $reset): void {
    $wire = new WireFixture;
    try {
        $f = new F($wire);
        $ref = new AdapterContractRef($f->code, 'detail', '1', '1');
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
            new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $ref),
        ]));
        if (! $canary) {
            DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        }
        $platform = DB::table('prc_platforms')->where('code', $f->code)->sole()->id;
        $registry = Registry::factory()->create(['platform_id' => $platform, 'code' => 'fz44']);
        ExecutionFixtures::authority([StageCode::Listing]);
        ExecutionFixtures::registerHandler(StageCode::Listing);
        $codec = new ValidatedAcquisitionCodec(new ListingStageCodec);
        app(StageRegistry::class)->bindFinalization(StageCode::Listing, $codec,
            new ValidatedAcquisitionFinalizer(app(ListingFinalizer::class), app(AdapterHealthRepository::class)));
        $run = ListingFixtures::run(registry: $registry);
        // The fixture work factory is deliberately generic; pin the real request platform.
        DB::table('prc_process_work')->where('id', $run['work']->value->value)->update(['platform_id' => $platform]);
        $lease = FinalizeFixtures::running($run['work']);
        $context = new ExecutionContext($lease, app(WorkStoreRepository::class), app(ProcessClock::class),
            Duration::seconds(240), Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
        $task = app(ListingStoreRepository::class)->task($run['input']);
        $outcome = app(AcquisitionValidation::class)->run($ref, $platform, new RoutePolicy([new HttpRouteBinding($f->route->routeId)]),
            $f->plan(), $wire->policy(), $context, function (PlatformOutbound $outbound) use ($f, $context, $task): StageSucceeded {
                expect($outbound->execute($f->plan(), $context))->toBeInstanceOf(TransportSucceeded::class);

                return new StageSucceeded(new ListingResult($task, ListingFixtures::page()));
            });
        expect($outcome)->toBeInstanceOf(StageSucceeded::class)->and($outcome->result)->toBeInstanceOf(ValidatedAcquisitionResult::class)
            ->and(DB::table('prc_outbound_operations')->count())->toBe($canary ? 1 : 0)
            ->and($wire->receipts())->toHaveCount(1);
        if ($canary) {
            expect(DB::table('prc_outbound_operations')->sole()->kind)->toBe('probe')
                ->and(DB::table('prc_outbound_operations')->sole()->state)->toBe('closed');
        }
        $encoded = $codec->encodeResult($outcome->result);
        expect($codec->decodeResult($encoded))->toEqual($outcome->result);
        $bad = json_decode($encoded->json, true, flags: JSON_THROW_ON_ERROR);
        $bad['receipt']['surprise'] = 1;
        expect(fn () => $codec->decodeResult(new VersionedPayload(json_encode($bad, JSON_THROW_ON_ERROR), 1)))
            ->toThrow(InvalidArgumentException::class);
        if ($reset) {
            DB::table('prc_adapter_health')->increment('health_epoch');
        }
        app(WorkFinalizer::class)->finish($lease, $outcome);
        $row = DB::table('prc_process_work')->where('id', $run['work']->value->value)->sole();
        expect($row->state)->toBe($reset ? 'blocked' : 'succeeded')
            ->and($row->item_failures)->toBe(0)
            ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe($reset ? 0 : 1);
    } finally {
        $wire->stop();
    }
})->with([[false, false], [false, true], [true, false], [true, true]]);
