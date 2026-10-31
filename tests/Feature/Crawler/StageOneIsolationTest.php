<?php

declare(strict_types=1);

use App\Actions\Procurement\Document\ExpandArchiveEntryAction;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Exceptions\Procurement\Ai\ScreeningDisabled;
use App\Exceptions\Procurement\Crawler\DownstreamDisabled;
use App\Exceptions\Procurement\OperationFailed;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\DownloadDocumentJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\RevalidateDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer;
use App\Services\Procurement\DocumentRevalidationSelector;
use App\Services\Procurement\Extraction\RerunAnalysisAction;
use App\Services\Procurement\Fixture\RunFixtureAnalysisAction;
use App\Services\Procurement\PipelineDispatcher;
use App\Services\Procurement\PipelineRecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\RefreshFixtures;

beforeEach(function () {
    config(['crawler.downstream_enabled' => false, 'ai.screening.enabled' => true, 'ai.extraction.enabled' => true]);
    Queue::fake();
    Http::fake();
});

test('legacy downstream pause blocks document backlog retry and revalidation without claims', function () {
    $procurement = Procurement::factory()->create();
    $document = Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Discovered, 'next_retry_at' => null]);
    Document::factory()->create(['procurement_id' => $procurement->id, 'status' => DocumentStatus::Downloaded, 'attempts' => 1]);

    $this->artisan('pipeline:retry-failed')->assertExitCode(0);
    $dispatcher = app(PipelineDispatcher::class);

    expect($dispatcher->dispatch($procurement))->toBe(0)
        ->and($dispatcher->dispatchBacklog(100))->toBe(0)
        ->and($dispatcher->dispatchDiscoveredBacklog(100))->toBe(0)
        ->and(app(DocumentRevalidationSelector::class)->dispatchDue(100))->toBe(0)
        ->and(app(DocumentRevalidationSelector::class)->dispatchForProcurement($procurement->id))->toBe(0)
        ->and(app(PipelineRecoveryReconciler::class)->reconcile(100))->toBe(0)
        ->and($document->fresh()->next_retry_at)->toBeNull();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('CSV-only останавливает уже поставленные document jobs до чтения данных и lease', function (string $jobClass) {
    // A nonexistent ID would fail if the status guard or domain work were entered.
    (new $jobClass(documentId: 999999))->handle();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([DownloadDocumentJob::class, PrepareDocumentJob::class, RevalidateDocumentJob::class, ScreeningJob::class, EstimateExtractionJob::class]);

test('legacy downstream pause leaves acquisition detail cycles durable without dispatch', function () {
    DetailFixtures::bind();
    $fixture = RefreshFixtures::dueDetail('downstream-paused');
    $allocated = app(RefreshRepository::class)->due(1);
    expect($allocated)->toHaveCount(1)
        ->and(DB::table('prc_process_work')->where('id', $allocated[0]->value->value)->sole()->state)->toBe('ready')
        ->and($fixture['p']->refresh()->detail_cycle_seq)->toBe(1);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('CSV-only блокирует ручные AI входы до изменения анализа и fixture', function () {
    expect(fn () => app(RerunAnalysisAction::class)->run(new Analysis))->toThrow(RuntimeException::class, 'CRAWLER_DOWNSTREAM_ENABLED');
    expect(fn () => app(RunFixtureAnalysisAction::class)->run(new Fixture))->toThrow(DownstreamDisabled::class, 'CRAWLER_DOWNSTREAM_ENABLED');
    app(EstimateAnalyzer::class)->dispatchExtraction(999999);

    Queue::assertNothingPushed();
});

test('отключённая стадия называет причину отказа fixture без рабочей копии и dispatch', function (bool $downstreamEnabled, bool $screeningEnabled, string $exceptionClass, string $disabledFlag): void {
    config(['crawler.downstream_enabled' => $downstreamEnabled, 'ai.screening.enabled' => $screeningEnabled]);
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    Platform::factory()->training()->create();
    $fixture = Fixture::factory()->create();
    Storage::disk($fixturesDisk)->put($fixture->storage_path, 'Synthetic fixture source');

    expect(fn () => app(RunFixtureAnalysisAction::class)->run($fixture))
        ->toThrow(function (OperationFailed $exception) use ($exceptionClass, $disabledFlag): void {
            expect($exception)->toBeInstanceOf($exceptionClass)
                ->and($exception->getMessage())->toContain($disabledFlag);
        });

    expect($fixture->fresh()->document_id)->toBeNull()
        ->and(Document::query()->count())->toBe(0)
        ->and(DocumentVersion::query()->count())->toBe(0)
        ->and(Storage::disk($documentsDisk)->allFiles())->toBe([]);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'downstream выключен, screening включён' => [false, true, DownstreamDisabled::class, 'CRAWLER_DOWNSTREAM_ENABLED'],
    'downstream включён, screening выключен' => [true, false, ScreeningDisabled::class, 'AI_SCREENING_ENABLED'],
    'оба выключены, downstream проверяется первым' => [false, false, DownstreamDisabled::class, 'CRAWLER_DOWNSTREAM_ENABLED'],
]);

test('enabling legacy downstream does not restore direct detail jobs or bypass Process', function () {
    config(['crawler.downstream_enabled' => true]);
    DetailFixtures::bind();
    $fixture = RefreshFixtures::dueDetail('downstream-enabled');
    $allocated = app(RefreshRepository::class)->due(1);
    expect($allocated)->toHaveCount(1)
        ->and(DB::table('prc_process_work')->where('id', $allocated[0]->value->value)->sole()->state)->toBe('ready')
        ->and($fixture['p']->refresh()->detail_cycle_seq)->toBe(1)
        ->and(app(RefreshRepository::class)->due(1))->toBe([]);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('CSV-only не диспатчит подготовку извлечённого дочернего документа архива', function () {
    Storage::fake('s3');
    $procurement = Procurement::factory()->create();
    $parent = Document::factory()->create(['procurement_id' => $procurement->id]);
    $version = DocumentVersion::factory()->create(['document_id' => $parent->id]);
    $path = storage_path('app/archive-child.txt');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }
    file_put_contents($path, 'Synthetic archive child');

    app(ExpandArchiveEntryAction::class)->execute($parent, $version, 'child.txt', $path);

    Queue::assertNothingPushed();
});
