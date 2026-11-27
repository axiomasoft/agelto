<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\Listing\ListingHandler;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures as C;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(function (): void {
    E::authority([StageCode::Listing]);
    F::bindLocal();
});

function freshProcessHandler(RecordingOutbound $outbound): ListingHandler
{
    $partitioner = new EisListingPartitioner;

    return new ListingHandler(app(ListingStoreRepository::class),
        new EisListingSource(new EisPolicy(config()), $outbound, LedgerFixtures::uuid()), $partitioner, $partitioner, new FetchOutcomeBridge);
}

test('Process fresh scans every page of known source days until two full passes agree', function (): void {
    config(['platforms.eis.block_size' => 1]);
    $run = F::run('2026-03-01', blockSize: 1);
    $bodies = [C::body([C::row('1')]), C::body([C::row('2', ['Дата обновления' => '01.01.2020'])]), C::body([])];
    $outbound = new RecordingOutbound(array_map(static fn (string $body) => new TransportSucceeded(
        new ResponseEnvelope(200, ['content-type' => ['text/csv']], new DecodedTextBody($body, 'UTF-8'), strlen($body), strlen($body))),
        [...$bodies, ...$bodies]));
    $handler = freshProcessHandler($outbound);
    for ($i = 0; $i < 6; $i++) {
        $next = F::next($run['crawl']) ?? throw new LogicException('Premature known-row stop.');
        $task = app(ListingStoreRepository::class)->task($next['input']);
        expect(EisListingCursor::decode($task->cursor)->firstRow)->toBe($i % 3 + 1);
        $lease = FinalizeFixtures::running($next['work']);
        app(WorkFinalizer::class)->finish($lease, $handler->execute($next['input'], app(ExecutionContextFactory::class)->open($lease)));
        if ($i === 3) {
            expect(F::next($run['crawl']))->not->toBeNull()
                ->and(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBeNull();
        }
    }
    expect($outbound->plans)->toHaveCount(6)->and(F::next($run['crawl']))->toBeNull()
        ->and(Procurement::query()->count())->toBe(2)
        ->and(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBe('2026-03-01');
});

test('Process fresh network waits and route holds preserve cursor and cannot claim source exhaustion', function (bool $hold): void {
    $run = F::run('2026-03-01');
    $before = DB::table('prc_crawl_blocks')->value('partition_payload');
    $outcome = $hold ? new TransportBlocked(NetworkBlockedReason::ConfigurationHold)
        : new TransportDeferred(NetworkDeferralReason::AttemptsExhausted, new RetryAt(new DateTimeImmutable('+1 minute')));
    $outbound = new RecordingOutbound([$outcome]);
    $lease = FinalizeFixtures::running($run['work']);
    $result = freshProcessHandler($outbound)->execute($run['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($result)->toBeInstanceOf($hold ? StageBlocked::class : StageDeferred::class);
    app(WorkFinalizer::class)->finish($lease, $result);
    expect(DB::table('prc_crawl_blocks')->value('partition_payload'))->toBe($before)
        ->and(DB::table('prc_crawl_blocks')->value('terminal_proof'))->toBeNull()
        ->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('collecting')
        ->and(DB::table('prc_process_work')->value('item_failures'))->toBe(0)
        ->and(Procurement::query()->count())->toBe(0)->and($outbound->plans)->toHaveCount(1);
})->with(['network wait' => [false], 'missing route' => [true]]);

test('Process fresh manual pause prohibits grants and retains the durable page assignment', function (): void {
    $run = F::run('2026-03-01');
    E::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_process_work')->value('execution_attempts'))->toBe(0)
        ->and(F::next($run['crawl'])['work'])->toEqual($run['work']);
});

test('Process coverage replaces legacy only-fresh stop policy and scan-gap heuristics', function (): void {
    $run = F::run('2026-03-01');
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBeNull()->and($coverage->resumeFrom('2026-02-01'))->toBe('2026-02-01')
        ->and(Schema::hasColumn('prc_crawlers', 'stop_policy'))->toBeFalse();
    F::finish($run['work'], $run['input'], F::page([F::summary('1')]));
    $next = F::next($run['crawl']);
    expect(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBeNull();
    F::finish($next['work'], $next['input'], F::page([F::summary('1')]));
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBe('2026-03-01')->and($coverage->resumeFrom('2026-02-01'))->toBe('2026-02-27');
});

test('Process fresh command after completed discovery creates a distinct run without rewriting history', function (): void {
    $profile = AcquisitionProfileFixtures::create();
    $launch = app(AcquisitionProfiles::class)->prepare($profile,
        AcquisitionKind::Fresh, new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-01'));
    $scheduler = app(AcquisitionScheduler::class);
    $first = $scheduler->manual($launch, LedgerFixtures::uuid(), null);
    $crawl = new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $first->value)->value('id'));
    while (($next = F::next($crawl)) !== null) {
        F::finish($next['work'], $next['input'], F::page());
    }
    $before = DB::table('prc_process_runs as r')->where('id', $first->value)->selectRaw('to_jsonb(r)::text AS snapshot')->value('snapshot');
    expect(json_decode($before, true)['discovery_outcome'])->toBe('complete');
    $second = $scheduler->manual($launch, LedgerFixtures::uuid(), null);
    expect($second)->not->toEqual($first)->and(DB::table('prc_process_runs as r')->where('id', $first->value)->selectRaw('to_jsonb(r)::text AS snapshot')->value('snapshot'))->toBe($before)
        ->and(DB::table('prc_process_runs')->where('id', $second->value)->value('discovery_outcome'))->toBe('collecting');
});
