<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\PrepareReservations;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Repositories\Procurement\Document\DocumentWorkRepository;
use App\Repositories\Procurement\Document\PrepareStorageRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\DocumentFeeder;
use App\Services\Procurement\Document\DocumentStorageReconciler;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DocumentFeederFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureAnalysisHandler;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

pest()->group('t10', 't11', 'p35');
beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
});

test('отключённые sources не мешают prepare verified bytes и оставляют real AI явно pending', function (): void {
    $f = new PrepareFixtures;
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    DB::table('prc_source_targets')->update(['retired_at' => now()]);
    Queue::fake();
    $tools = new StubPdfTools(1);
    do {
        $ctx = $f->context();
        $outcome = $f->handler($tools)->execute($f->input, $ctx);
        app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    } while ($outcome instanceof StagePartial);
    expect($outcome)->toBeInstanceOf(StageSucceeded::class)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('succeeded')
        ->and(DB::table('prc_operation_intents')->where('stage', 'screening')->value('pending_reason'))->toBe('handler_not_activated')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('manual и две площадки получают одинаковый fixture анализ при paused acquisition', function (): void {
    FinalizeFixtures::authority([StageCode::Screening]);
    $handler = new FixtureAnalysisHandler;
    app(StageRegistry::class)->bindHandler(StageCode::Screening, $handler);
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    foreach (['manual', 'platform:aaa', 'platform:bbb'] as $cohort) {
        $work = DocumentFeederFixtures::analysis($cohort);
        app(WorkFeeder::class)->tick('analysis');
        (new ExecuteWorkJob($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value))
            ->handle(app(WorkRunner::class));
    }
    expect($handler->calls)->toBe(3)
        ->and(ExecutionFixtures::laneCounts('analysis'))->toBe(['outstanding' => 0, 'running' => 0])
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('standalone producer восстанавливает missing prepare один раз и не сбрасывает blocked или terminal history', function (): void {
    $f = new PrepareFixtures;
    $before = DB::table('prc_process_work')->count();
    $producer = app(DocumentFeeder::class);
    Queue::fake();
    expect($producer->reconcile())->toBe(0)->and($producer->reconcile())->toBe(0)
        ->and(DB::table('prc_process_work')->count())->toBe($before);
    DB::table('prc_process_work')->where('id', $f->work->value->value)->update(['state' => 'blocked', 'reason_code' => 'document_quality']);
    expect($producer->reconcile())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('state'))->toBe('blocked');
    Queue::assertNothingPushed();
});

test('standalone activation отделена от admission и повторного queue dispatch', function (): void {
    $f = new PrepareFixtures;
    $doc = DB::table('prc_document_versions')->where('id', $f->input->documentVersionId)->value('document_id');
    $intent = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeIntent($intent,
        ContentHash::of('initial:'.$intent->value), StageCode::Download,
        new SubjectKey(SubjectKind::Document, $doc),
        ContentHash::of('semantic'), ContentHash::of('access'), 1, 1, IntentState::Pending));
    Queue::fake();
    $works = app(DocumentWorkRepository::class);
    expect($works->activate($doc))->toBe(1)->and($works->activate($doc))->toBe(0)
        ->and(DB::table('prc_operation_intents')->where('id', $intent->value)->value('state'))->toBe('active')
        ->and(DB::table('prc_process_work')->where('stage', 'download')->value('state'))->toBe('ready')
        ->and(DB::table('prc_process_work')->where('stage', 'download')->value('input_revision'))->toBe(2)
        ->and(DB::table('prc_admission_groups')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('analysis defer освобождает parent child slots сохраняя immutable age и future due', function (): void {
    FinalizeFixtures::authority([StageCode::Screening]);
    $work = DocumentFeederFixtures::analysis('manual');
    ExecutionFixtures::age($work, 1000);
    $age = DB::table('prc_process_work')->where('id', $work->value->value)->value('created_at');
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, new StageDeferred(DeferralReason::ProviderUnavailable, new RetryAt(new DateTimeImmutable('+1 hour'))));
    expect(app(WorkFeeder::class)->tick('analysis'))->toBe(0)
        ->and(ExecutionFixtures::laneCounts('analysis')['outstanding'])->toBe(0)
        ->and(ExecutionFixtures::laneCounts('analysis.screening')['outstanding'])->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('created_at'))->toBe($age)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0);
});

test('storage reconcile ждёт generation revocation и verified workspace stop вместо одного lease expiry', function (): void {
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $r = app(PrepareStorageRepository::class)->reserve($ctx->lease());
    expect($r)->toBeInstanceOf(PrepareReservations::class);
    app(PrepareWorkspace::class)->open($r->workspace);
    $cleanup = app(DocumentStorageReconciler::class);
    expect($cleanup->reconcile())->toBe(0);
    FinalizeFixtures::expire($f->work);
    expect($cleanup->reconcile())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->where('id', $r->workspace->id->value)->value('status'))->toBe('writing');
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    expect($cleanup->reconcile())->toBe(3)->and($cleanup->reconcile())->toBe(0)
        ->and(DB::table('prc_storage_reservations')->where('work_id', $f->work->value->value)->where('status', 'cleaned')->count())->toBe(3)
        ->and(DB::table('prc_process_work')->where('id', $f->work->value->value)->value('item_failures'))->toBe(0);
});
