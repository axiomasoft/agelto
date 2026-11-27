<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Document\Download\DocumentReference;
use App\Dto\Procurement\Document\Download\SourceDocumentKey;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Acquisition\Detail\DetailArtifacts;
use App\Services\Procurement\Acquisition\Detail\DetailHandler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

function detailProcessJob(array $f, DetailFixtureSource $source): ExecuteWorkJob
{
    app(StageRegistry::class)->bindHandler(StageCode::Detail, new DetailHandler(
        app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge));
    app(WorkFeeder::class)->tick('acquisition');

    return new ExecuteWorkJob($f['work']->value->value, ExecutionFixtures::delivery($f['work'])->value, LedgerFixtures::uuid()->value);
}

beforeEach(fn () => F::bind());

test('Process detail delivery publishes document references and pending intents without a pipeline bridge', function (): void {
    Queue::fake();
    $f = F::work();
    $base = F::snapshot($f['request'], 'documents');
    $reference = new DocumentReference($base->identity, 'attachment', 'Estimate',
        new SafeUrl('https://example.org/file?sig=fixture'), new SourceDocumentKey('attachment:1', 1));
    $snapshot = new ProcurementSnapshot($base->identity, $base->title, $base->sourceStage,
        [], [], [], [$reference], $base->parts);
    $source = new DetailFixtureSource([new DetailFetched($snapshot), new DetailFetched($snapshot)], ['documents']);
    $job = detailProcessJob($f, $source);
    $job->handle(app(WorkRunner::class));
    $job->handle(app(WorkRunner::class));
    expect(ExecutionFixtures::work($f['work'])->state)->toBe('succeeded')
        ->and($source->calls)->toBe(['documents', 'documents'])
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(1)
        ->and(DB::table('prc_procurement_documents')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->sole()->state)->toBe('pending')
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->sole()->pending_reason)->toBe('handler_not_activated');
    Queue::assertNothingPushed();
});

test('Process detail network budget defers without consuming item failures or dispatching documents', function (): void {
    Queue::fake();
    $f = F::work();
    DB::table('prc_process_work')->where('id', $f['work']->value->value)->update(['item_failures' => 2]);
    $retry = new RetryAt(new DateTimeImmutable('+1 hour'));
    $source = new DetailFixtureSource([new FetchDeferred(NetworkDeferralReason::RateLimit, $retry)]);
    detailProcessJob($f, $source)->handle(app(WorkRunner::class));
    $row = ExecutionFixtures::work($f['work']);
    expect($row->state)->toBe('deferred')->and($row->reason_code)->toBe('rate_limit')
        ->and($row->item_failures)->toBe(2)->and($row->owner_token)->toBeNull()
        ->and(new DateTimeImmutable($row->due_at))->toBeGreaterThanOrEqual($retry->value)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('Process detail delivery uses the registered queue and PostgreSQL identity without a Redis unique lock', function (): void {
    $f = F::work();
    $route = app(StageQueueMap::class)->forStage(StageCode::Detail);
    $job = new ExecuteWorkJob($f['work']->value->value, 1, LedgerFixtures::uuid()->value);
    expect($job)->toBeInstanceOf(ShouldQueue::class)->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->workId)->toBe($f['work']->value->value)
        ->and($route->connection)->toBe('process_short')->and($route->queue)->toBe('process-acquisition')
        ->and($route->jobTimeout)->toBeLessThan($route->horizonTimeout)
        ->and($route->retryAfter)->toBeLessThan($route->leaseSeconds);
});

test('Process detail worker timeout callback is telemetry only and durable recovery retains budget and checkpoint', function (): void {
    $f = F::work();
    $lease = FinalizeFixtures::running($f['work']);
    app(WorkStoreRepository::class)->checkpoint($lease, new DetailProgress, 0);
    $before = AcquisitionProfileFixtures::workSnapshot($f['work']->value->value);
    (new ExecuteWorkJob($f['work']->value->value, 1, LedgerFixtures::uuid()->value))->failed(new RuntimeException('worker timeout'));
    expect(AcquisitionProfileFixtures::workSnapshot($f['work']->value->value))->toBe($before);
    FinalizeFixtures::expire($f['work']);
    FixtureProviderRecoveryResolver::install();
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($f['work'])->state)->toBe('ready')
        ->and(ExecutionFixtures::work($f['work'])->item_failures)->toBe(0)
        ->and(ExecutionFixtures::work($f['work'])->checkpoint)->not->toBeNull()
        ->and(ExecutionFixtures::work($f['work'])->owner_token)->toBeNull();
});
