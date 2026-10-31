<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\DetailArtifacts;
use App\Services\Procurement\Acquisition\DetailHandler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures as F;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::authority([StageCode::Listing]));

test('Process repeated handler faults retain cursor and block the work without consuming item budget or source health', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    $saved = app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(501), 0);
    app(WorkFinalizer::class)->finishHandlerFault($lease->checkpointed($saved), F::fault('same-code'));
    foreach (range(2, 3) as $unused) {
        app(WorkFinalizer::class)->finishHandlerFault(F::rerun($work), F::fault('same-code'));
    }
    expect(E::work($work)->state)->toBe('blocked')->and(E::work($work)->reason_code)->toBe('handler_fault_limit')
        ->and(E::work($work)->item_failures)->toBe(0)
        ->and((int) DB::table('prc_crawl_blocks')->where('id', E::work($work)->crawl_block_id)->value('cursor->position'))->toBe(501)
        ->and(DB::table('prc_process_incidents')->where('kind', 'handler_fault_limit')->count())->toBe(1)
        ->and(DB::table('prc_observations')->count())->toBe(0)->and(SourceIncident::query()->count())->toBe(0);
});

test('late handler failure cannot resurrect cancelled Process work or publish a misleading incident', function (): void {
    $work = E::listingWork();
    $lease = F::running($work);
    app(CancelWork::class)->execute($work, 'operator hard cancel');
    $before = AcquisitionProfileFixtures::workSnapshot($work->value->value);
    expect(app(WorkFinalizer::class)->finishHandlerFault($lease, F::fault('late-fault'))->status->value)->toBe('lost_ownership')
        ->and(AcquisitionProfileFixtures::workSnapshot($work->value->value))->toBe($before)
        ->and(E::work($work)->state)->toBe('cancelled')
        ->and(DB::table('prc_process_incidents')->count())->toBe(0)->and(SourceIncident::query()->count())->toBe(0);
});

test('database dependency interruption leaves durable running work for recovery without a false failure', function (): void {
    $work = E::listingWork();
    app(StageRegistry::class)->bindHandler(StageCode::Listing, new class implements StageHandler
    {
        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            throw new DependencyUnavailable('database');
        }
    });
    app(WorkFeeder::class)->tick('acquisition');
    $job = new ExecuteWorkJob($work->value->value, E::delivery($work)->value, LedgerFixtures::uuid()->value);
    $job->handle(app(WorkRunner::class));
    expect(E::work($work)->state)->toBe('running')->and(E::work($work)->item_failures)->toBe(0)
        ->and(DB::table('prc_process_attempts')->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0);
    F::expire($work);
    FixtureProviderRecoveryResolver::install();
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(E::work($work)->state)->toBe('ready')->and(E::work($work)->item_failures)->toBe(0);
});

test('EIS unrecognized card stays bounded incomplete and preserves canonical documents', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work(value: '00042');
    $document = Document::factory()->create(['procurement_id' => $f['p']->id]);
    $outbound = new RecordingOutbound(array_fill(0, 3,
        new TransportSucceeded(ContractRuntime::response('<html><body>00042 неизвестная вёрстка</body></html>', 'text/html'))));
    $handler = new DetailHandler(app(DetailSnapshotRepository::class), EisContractManifest::detail($outbound),
        app(DetailArtifacts::class), new FetchOutcomeBridge);
    foreach (range(1, 3) as $attempt) {
        $lease = F::running($f['work']);
        $out = $handler->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $out);
        expect(E::work($f['work'])->state)->toBe($attempt === 3 ? 'blocked' : 'ready');
    }
    expect($outbound->plans)->toHaveCount(3)->and(E::work($f['work'])->reason_code)->toBe('policy_review')
        ->and(E::work($f['work'])->item_failures)->toBe(0)
        ->and(Document::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0);
});

test('legacy incident navigation and badge remain readable while every acquisition incident writer is absent', function (): void {
    $incident = SourceIncident::factory()->create();
    expect(Schema::hasTable('prc_source_incidents'))->toBeTrue()->and(Schema::hasTable('prc_source_circuits'))->toBeFalse()
        ->and(SourceIncidentResource::getNavigationBadge())->toBe('1')
        ->and(SourceIncident::query()->findOrFail($incident->id)->message)->toBe($incident->message);
    foreach (['SourceEventReporter', 'SourceIncidentPublisher', 'SourceEventLogger'] as $writer) {
        expect(is_file(app_path('Services/Procurement/SourceControl/'.$writer.'.php')))->toBeFalse();
    }
    expect(is_file(app_path('Repositories/Procurement/Crawler/SourceIncidentRepository.php')))->toBeFalse();
});
