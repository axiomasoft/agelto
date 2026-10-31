<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\FixtureCorpus;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\Platforms\JsonProcurement\JsonContractManifest;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t39', 'p22');

/** @param Closure(ExecutionContext): StageOutcome $execute */
function contractBridgeHandler(StageCode $stage, Closure $execute): object
{
    $handler = new class($execute) implements StageHandler
    {
        public int $calls = 0;

        public function __construct(private Closure $execute) {}

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;

            return ($this->execute)($execution);
        }
    };
    app(StageRegistry::class)->bindHandler($stage, $handler);

    return $handler;
}

function contractBridgeDelivery(WorkId $work): DeliveryOutcome
{
    DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE id = ? AND state = 'deferred'", [$work->value->value]);
    app(WorkFeeder::class)->tick('acquisition');

    return app(WorkRunner::class)->runDelivery(DeliveryEnvelope::fromScalars($work->value->value,
        ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value, 1));
}

test('T39 adapter transport failures через настоящий WorkRunner сохраняют state reason due и budgets', function (string $platform, string $scenario): void {
    $repository = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $retry = new RetryAt(app(ProcessClock::class)->dbNow()->value->modify('+60 seconds'));
    [$transport, $state, $reason] = match ($scenario) {
        'no_route' => [new TransportDeferred(NetworkDeferralReason::NoRoute, $retry), 'deferred', 'no_route'],
        'exhausted' => [new TransportDeferred(NetworkDeferralReason::AttemptsExhausted, $retry), 'deferred', 'target_cooldown'],
        'rate' => [new TransportDeferred(NetworkDeferralReason::RateLimit, $retry), 'deferred', 'rate_limit'],
        'held' => [new TransportBlocked(NetworkBlockedReason::AdapterHeld), 'blocked', 'handler_hold'],
    };
    $outbound = new RecordingOutbound([$transport]);
    $handler = contractBridgeHandler(StageCode::Listing, function (ExecutionContext $context) use ($platform, $outbound): StageOutcome {
        $result = $platform === 'eis' ? EisContractManifest::listing($outbound)->fetch(EisContractManifest::request(), $context)
            : JsonContractManifest::source($outbound)->fetchListing(JsonContractManifest::request(), $context);

        return (new FetchOutcomeBridge)->failure($result);
    });
    $work = ExecutionFixtures::listingWork();
    expect(contractBridgeDelivery($work))->toBe(DeliveryOutcome::Executed);
    $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->item_failures, $row->semantic_reasks, $row->transport_attempts, $row->execution_attempts])
        ->toBe([$state, $reason, 0, 0, 0, 1])
        ->and($row->due_at === null)->toBe($state === 'blocked')
        ->and($handler->calls)->toBe(1)->and($outbound->plans)->toHaveCount(1)
        ->and([$repository->resultWrites, $repository->progressWrites])->toBe([0, 0])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0);
    if ($row->due_at !== null) {
        // The existing finalizer adds nonnegative jitter of at most 10%; it never shortens RetryAt.
        expect((float) (new DateTimeImmutable($row->due_at))->format('U.u'))
            ->toBeGreaterThanOrEqual((float) $retry->value->format('U.u'))
            ->toBeLessThanOrEqual((float) $retry->value->modify('+6 seconds')->format('U.u'));
    }
})->with(['eis', 'json'])->with(['no_route', 'exhausted', 'rate', 'held']);

test('T39 truncated listing становится partial checkpoint без canonical publication и item failure', function (string $platform): void {
    $repository = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $path = $platform === 'eis' ? EisContractManifest::CSV.'contract-truncated.csv' : JsonContractManifest::ROOT.'contract-truncated.json';
    $outbound = new RecordingOutbound([new TransportSucceeded(ContractRuntime::response(FixtureCorpus::read($path), $platform === 'eis' ? 'text/csv' : 'application/json'))]);
    contractBridgeHandler(StageCode::Listing, function (ExecutionContext $context) use ($platform, $outbound): StageOutcome {
        $result = $platform === 'eis' ? EisContractManifest::listing($outbound)->fetch(EisContractManifest::request(), $context)
            : JsonContractManifest::source($outbound)->fetchListing(JsonContractManifest::request(), $context);

        return (new FetchOutcomeBridge)->partial($result, new FixtureStageCheckpoint(0));
    });
    $work = ExecutionFixtures::listingWork();
    expect(contractBridgeDelivery($work))->toBe(DeliveryOutcome::Executed);
    $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->item_failures, $row->checkpoint_seq])->toBe(['ready', null, 0, 1])
        ->and($repository->resultWrites)->toBe(0)->and($repository->progressWrites)->toBe(1)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
})->with(['eis', 'json']);

test('T39 missing required detail и schema failure не расходуют item budget', function (string $platform): void {
    $repository = FinalizeFixtures::authority([StageCode::Detail])['detail'];
    $responses = $platform === 'eis' ? [
        new TransportSucceeded(ContractRuntime::response(FixtureCorpus::read(EisContractManifest::CARD.'contract-common.html'), 'text/html')),
        new TransportSucceeded(ContractRuntime::response('', 'text/html')),
    ] : [new TransportSucceeded(ContractRuntime::response('{"notice":{}}', 'application/json'))];
    $outbound = new RecordingOutbound($responses);
    contractBridgeHandler(StageCode::Detail, function (ExecutionContext $context) use ($platform, $outbound): StageOutcome {
        $result = $platform === 'eis' ? EisContractManifest::detail($outbound)->fetch(EisContractManifest::detailRequest(), $context)
            : JsonContractManifest::source($outbound)->fetchDetail(JsonContractManifest::detailRequest(), $context);

        return $result instanceof FetchItemRejected ? (new FetchOutcomeBridge)->failure($result)
            : (new FetchOutcomeBridge)->partial($result, new FixtureStageCheckpoint(0, StageCode::Detail));
    });
    $work = ExecutionFixtures::detailWork();
    $before = DB::table('prc_procurements')->get()->toJson();
    expect(contractBridgeDelivery($work))->toBe(DeliveryOutcome::Executed);
    $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->item_failures])->toBe($platform === 'eis' ? ['ready', null, 0] : ['blocked', 'handler_hold', 0])
        ->and($repository->resultWrites)->toBe(0)
        ->and(DB::table('prc_procurements')->get()->toJson())->toBe($before);
})->with(['eis', 'json']);

test('T39 три genuine identity rejects с artifact расходуют только item budget и четвёртая delivery не fetch', function (string $platform): void {
    $repository = FinalizeFixtures::authority([StageCode::Detail])['detail'];
    $path = $platform === 'eis' ? EisContractManifest::CARD.'contract-common.html' : JsonContractManifest::ROOT.'detail.json';
    $body = str_replace($platform === 'eis' ? '№ 00042' : 'Ab-/42', $platform === 'eis' ? '№ 000420' : 'ab-/42', FixtureCorpus::read($path));
    Storage::disk('local')->put('contract/rejected-response', $body);
    $hash = ContentHash::of($body);
    $response = new TransportSucceeded(ContractRuntime::response($body, $platform === 'eis' ? 'text/html' : 'application/json'));
    $outbound = new RecordingOutbound(array_fill(0, $platform === 'eis' ? 6 : 3, $response));
    $handler = contractBridgeHandler(StageCode::Detail, function (ExecutionContext $context) use ($platform, $outbound, $hash): StageOutcome {
        $result = $platform === 'eis' ? EisContractManifest::detail($outbound)->fetch(EisContractManifest::detailRequest(), $context)
            : JsonContractManifest::source($outbound)->fetchDetail(JsonContractManifest::detailRequest(), $context);
        expect($result)->toBeInstanceOf(FetchItemRejected::class)->and($result->assessment->bodyHash->hex)->toBe($hash->hex);
        $assessment = $result->assessment;
        $withArtifact = new FetchItemRejected($result->reason, new ResponseAssessment($assessment->completeness,
            $assessment->reason, $assessment->bodyHash, $assessment->confidence, new ArtifactRef('local', 'contract/rejected-response', $hash)));

        return (new FetchOutcomeBridge)->failure($withArtifact);
    });
    $work = ExecutionFixtures::detailWork();
    foreach ([1, 2, 3] as $failures) {
        expect(contractBridgeDelivery($work))->toBe(DeliveryOutcome::Executed);
        $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
        expect([$row->state, $row->reason_code, $row->item_failures])->toBe($failures === 3 ? ['failed_item', 'contract_rejected', 3] : ['deferred', 'item_retry', $failures]);
    }
    expect(contractBridgeDelivery($work))->toBe(DeliveryOutcome::Rejected)
        ->and($handler->calls)->toBe(3)->and($repository->resultWrites)->toBe(0)
        ->and($outbound->plans)->toHaveCount($platform === 'eis' ? 6 : 3)
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0);
})->with(['eis', 'json']);

test('T39 bridge исчерпывает network reasons и owner lost не превращается в item failure', function (): void {
    $bridge = new FetchOutcomeBridge;
    $retry = new RetryAt(new DateTimeImmutable('+1 hour'));
    foreach (NetworkDeferralReason::cases() as $reason) {
        expect($bridge->failure(new FetchDeferred($reason, $retry))->retryAt)->toBe($retry);
    }
    foreach (NetworkBlockedReason::cases() as $reason) {
        if ($reason === NetworkBlockedReason::OwnerLost) {
            expect(fn () => $bridge->failure(new FetchBlocked($reason)))->toThrow(LeaseLost::class);
        } else {
            expect($bridge->failure(new FetchBlocked($reason)))->toBeInstanceOf(StageBlocked::class);
        }
    }
});

test('T39 genuine rejection требует persisted evidence именно assessed bytes', function (): void {
    $body = str_replace('Ab-/42', 'ab-/42', FixtureCorpus::read(JsonContractManifest::ROOT.'detail.json'));
    $result = JsonContractManifest::source()->parseDetail(JsonContractManifest::detailRequest(), ContractRuntime::response($body, 'application/json'));
    expect($result)->toBeInstanceOf(FetchItemRejected::class);
    expect(fn () => (new FetchOutcomeBridge)->failure($result))->toThrow(InvalidArgumentException::class, 'persisted assessment artifact');
    $assessment = $result->assessment;
    $wrong = new FetchItemRejected($result->reason, new ResponseAssessment($assessment->completeness, $assessment->reason,
        $assessment->bodyHash, artifact: new ArtifactRef('local', 'wrong', LedgerFixtures::hash('wrong'))));
    expect(fn () => (new FetchOutcomeBridge)->failure($wrong))->toThrow(InvalidArgumentException::class, 'does not match assessed bytes');
});
