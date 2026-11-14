<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\AcquisitionAssessment;
use App\Services\Procurement\Acquisition\AcquisitionValidation;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\CallerOutbound;
use App\Services\Procurement\Proxyma\CallerOutbound as OutboundExecutor;
use App\Services\Procurement\Proxyma\ProfileBindings as RoutePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

pest()->group('provider-send-fencing', 'work-controls-and-fences');
beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});

/** @return array{F, ExecutionContext, AdapterContractRef} */
function workAdapterFixture(WireFixture $wire): array
{
    $f = new F($wire);
    $contract = new AdapterContractRef($f->code, 'detail', '1', '1');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $contract),
    ]));
    HistoricalNetworkFixtures::synchronize();
    ProxymaCallerFixtures::install($wire);
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    DB::table('prc_process_work')->where('id', $work->value->value)->update([
        'platform_id' => DB::table('prc_platforms')->where('code', $f->code)->sole()->id,
    ]);
    $lease = FinalizeFixtures::running($work);
    $context = new ExecutionContext($lease, app(WorkStoreRepository::class), app(ProcessClock::class),
        Duration::seconds(240), Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));

    return [$f, $context, $contract];
}

function workAdapterPlan(F $f, AdapterValidationClaim $claim): OutboundRequestPlan
{
    $p = $f->plan();

    return new OutboundRequestPlan($p->physicalRequestId, $p->targetId, $p->method,
        $p->url, $p->profile, $p->trafficClass, $p->idempotency, adapterClaim: $claim);
}

test('Process validation uses its live attempt and physical provenance without an ordinary bridge', function (bool $late): void {
    $wire = new WireFixture;
    try {
        [$f, $context, $contract] = workAdapterFixture($wire);
        $repository = app(AdapterHealthRepository::class);
        $tx = app(ProcessTransaction::class);
        expect($tx->run(fn () => $repository->admit($contract, $context)))->toBeInstanceOf(TransportBlocked::class);
        // Prior canary is fixture setup, never an ordinary work capability.
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $claim = $tx->run(fn () => $repository->admit($contract, $context));
        expect($claim)->toBeInstanceOf(AdapterValidationClaim::class)
            ->and($claim->ownerKind)->toBe(AdapterValidationOwnerKind::WorkAttempt)
            ->and($claim->ownerId->value)->toBe($context->lease()->attemptId->value);
        $context->bindAdapter($claim);
        $result = app(OutboundExecutor::class)->execute(workAdapterPlan($f, $claim), $context,
            $f->gate(), $wire->policy(), new TransferBudget(65536, 65536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class);
        $context->recordSuccessfulRequest($result->physicalRequestId);
        if ($late) {
            DB::table('prc_adapter_health')->increment('health_epoch');
        }
        $inspection = new AdapterInspection('parsed', AdapterVerdict::Accepted, 'fixture.full_contract');
        $receipt = $tx->run(fn () => $repository->observe($context, $context->lease()->inputHash, $inspection));
        expect($receipt->stale)->toBe($late)
            ->and($receipt->ownerKind)->toBe(AdapterValidationOwnerKind::WorkAttempt)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(0)
            ->and(DB::table('pxm_operations')->sole()->owner_id)->toBe($claim->ownerId->value)
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(1);
        $duplicate = $tx->run(fn () => $repository->observe($context, $context->lease()->inputHash, $inspection));
        expect($duplicate)->toEqual($receipt);
        if (! $late) {
            $tx->run(fn () => $repository->assertReceipt($receipt));
        }
    } finally {
        $wire->stop();
    }
})->with([false, true]);

test('Сохранение evidence сохраняет item scope и не объявляет исправный адаптер suspect', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $context, $contract] = workAdapterFixture($wire);
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $platform = DB::table('prc_platforms')->where('code', $f->code)->sole()->id;
        $result = app(AcquisitionValidation::class)->run($contract, $platform,
            new RoutePolicy([new HttpRouteBinding($f->route->routeId)]), $f->plan(), $wire->policy(), $context,
            function (PlatformOutbound $outbound, AcquisitionAssessment $assessment) use ($f, $context): StageFailedItem {
                $response = $outbound->execute($f->plan(), $context);
                expect($response)->toBeInstanceOf(TransportSucceeded::class);
                $rejected = $assessment->rejection(new FetchItemRejected(ItemFailureReason::ContractRejected,
                    new ResponseAssessment(Completeness::Partial, AssessmentReason::SchemaMismatch,
                        ContentHash::of($response->response->body->utf8), failureScope: FailureScope::Item)));
                $assessment->observe($rejected);

                return app(FetchOutcomeBridge::class)->failure($rejected);
            });
        expect($result)->toBeInstanceOf(StageFailedItem::class)
            ->and(Storage::disk($result->evidence->disk)->get($result->evidence->key))->toBe('Привет')
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('healthy')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->value('details->verdict'))->toBe('partial')
            ->and($wire->receipts())->toHaveCount(1);
    } finally {
        $wire->stop();
    }
});

test('Process send rejects epoch takeover lease loss and forged operation identity before physical authorization', function (string $change): void {
    $wire = new WireFixture;
    try {
        [$f, $context, $contract] = workAdapterFixture($wire);
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $claim = app(ProcessTransaction::class)->run(fn () => app(AdapterHealthRepository::class)->admit($contract, $context));
        $context->bindAdapter($claim);
        $plan = workAdapterPlan($f, $claim);
        if ($change === 'forged') {
            $forged = new AdapterValidationClaim($claim->healthId, $claim->epoch, $contract, LedgerFixtures::uuid(), false);
            $plan = workAdapterPlan($f, $forged);
        } else {
            match ($change) {
                'epoch' => DB::table('prc_adapter_health')->increment('health_epoch'),
                'takeover' => DB::table('prc_process_work')->where('id', $context->lease()->workId->value->value)->increment('generation'),
                'lease' => FinalizeFixtures::expire($context->lease()->workId),
            };
        }
        expect(app(CallerOutbound::class)->execute($plan, $context, $f->gate(), $wire->policy(),
            new TransferBudget(65536, 65536)))->toBeInstanceOf(TransportBlocked::class);
        expect($wire->receipts())->toHaveCount(0)->and(DB::table('pxm_attempts')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
})->with(['epoch', 'takeover', 'lease', 'forged']);

test('a real-work canary cannot send after its parent loses authority', function (string $change): void {
    $wire = new WireFixture;
    try {
        [$f, $context, $contract] = workAdapterFixture($wire);
        $platform = DB::table('prc_platforms')->where('code', $f->code)->sole()->id;
        app(AcquisitionValidation::class)->run($contract, $platform, new RoutePolicy([new HttpRouteBinding($f->route->routeId)]),
            $f->plan(), $wire->policy(), $context, function (PlatformOutbound $outbound) use ($f, $context, $change): StageBlocked {
                expect($outbound->execute($f->plan(), $context))->toBeInstanceOf(TransportSucceeded::class);
                match ($change) {
                    'takeover' => DB::table('prc_process_work')->where('id', $context->lease()->workId->value->value)->increment('generation'),
                    'lease', 'sql_expiry' => FinalizeFixtures::expire($context->lease()->workId),
                    'hold' => ExecutionFixtures::holdHandler(StageCode::Listing),
                };
                expect($outbound->execute($f->plan(), $context))->toBeInstanceOf(TransportBlocked::class);

                return new StageBlocked(BlockedReason::HandlerHold);
            });
        expect($wire->receipts())->toHaveCount(1)
            ->and(DB::table('pxm_attempts')->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->sole()->parent_attempt_id)->toBe($context->lease()->attemptId->value)
            ->and(DB::table('prc_outbound_operations')->sole()->state)->toBe('closed');
    } finally {
        $wire->stop();
    }
})->with(['takeover', 'lease', 'hold', 'sql_expiry']);
