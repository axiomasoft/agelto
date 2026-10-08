<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Modes\ArchiveRunProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\SourceControl\SourceCircuitBreaker;
use App\Services\Procurement\SourceControl\SuspendedRunResumer;
use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Crawler\SourceControlScenario as S;
use Tests\Support\Doubles\Crawler\ScriptedSourceMethod;

beforeEach(function (): void {
    Storage::fake('s3');
    Queue::fake();
});

test('deadline листинга даёт checkpoint без инцидента и без продвижения курсора', function (CrawlerMode $mode): void {
    $crawler = Crawler::factory()->create(['mode' => $mode]);
    $source = Mockery::mock(DeadlineAwareSourceMethod::class);
    $source->shouldReceive('isAvailable')->andReturnTrue();
    $source->shouldReceive('collectListingWithinDeadline')->once()
        ->withArgs(fn ($crawler, $cursor, float $deadlineAt): bool => $cursor->blockFrom === 1 && $deadlineAt > microtime(true))
        ->andThrow(OutboundRequestDeadlineExceeded::forStage('listing'));
    app()->instance($source::class, $source);
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => $source::class]]);

    if ($mode === CrawlerMode::Fresh) {
        $run = app(FreshRunProcessor::class)->run(crawler: $crawler);
    } else {
        $run = Run::factory()->for($crawler)->create(['cursor' => null]);
        expect(app(ArchiveWindowScanner::class)->scan(
            crawler: $crawler, run: $run,
            filter: new WindowFilter(dateField: 'publish', dateFrom: now()->startOfDay(), dateTo: now()->startOfDay()),
        ))->toBe(ArchiveWindowScanResult::Checkpointed);
    }

    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->cursor['blockFrom'] ?? 1)->toBe(1)
        ->and(SourceIncident::query()->count())->toBe(0)
        ->and(Procurement::query()->count())->toBe(0);
})->with([CrawlerMode::Fresh, CrawlerMode::Archive]);

test('архив не начинает новый бюджет времени при переходе на следующий день', function (): void {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $deadlines = [];
    $source = Mockery::mock(DeadlineAwareSourceMethod::class);
    $source->shouldReceive('isAvailable')->andReturnTrue();
    $source->shouldReceive('collectListingWithinDeadline')->times(4)
        ->andReturnUsing(function ($crawler, $cursor, float $deadlineAt) use (&$deadlines): ListingBatch {
            $deadlines[] = $deadlineAt;

            return S::end();
        });
    app()->instance($source::class, $source);
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => $source::class]]);

    $run = app(ArchiveRunProcessor::class)->run(crawler: $crawler, from: now()->subDay()->startOfDay(), to: now()->startOfDay());

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($deadlines)->toHaveCount(4)
        ->and($deadlines[0])->toBe($deadlines[1]);
});

test('единичный отказ листинга сохраняет курсор и повторяет тот же блок после backoff', function (string $failure): void {
    $crawler = Crawler::factory()->create();
    $step = match ($failure) {
        '503' => S::status(503),
        'empty' => S::emptyBody(),
        'code' => S::bug(),
        'row' => new ListingBatch(
            rows: [S::row('0173200001234000001')], rawBody: 'partial', dedupSignature: 'first',
            rowsTotal: 2, httpStatus: 200, durationMs: 1, requestParams: [], rowsMalformed: 1,
        ),
    };
    $source = S::install(new ScriptedSourceMethod([$step, S::rows(2), S::end()]));
    $processor = app(FreshRunProcessor::class);

    $run = $processor->run(crawler: $crawler);
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->cursor['blockFrom'])->toBe(1)
        ->and($source->requestedBlocks)->toBe([1]);

    $processor->run(crawler: $crawler);
    expect($source->requestedBlocks)->toBe([1]);

    $this->travel(11)->minutes();
    $run = $processor->run(crawler: $crawler);
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($source->requestedBlocks)->toBe([1, 1, 501]);
})->with(['503', 'empty', 'code', 'row']);

test('fresh-ответ бывшего владельца не записывает закупки после перехвата аренды', function (): void {
    $crawler = Crawler::factory()->create();
    $runs = app(CrawlerRunManager::class);
    $run = $runs->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
    expect($runs->acquireExecutionLease(run: $run))->toBeTrue();
    $source = Mockery::mock(SourceMethod::class);
    $source->shouldReceive('isAvailable')->andReturnTrue();
    $source->shouldReceive('collectListing')->once()->andReturnUsing(function () use ($run): ListingBatch {
        Run::query()->whereKey($run->id)->update(['execution_owner_token' => '00000000-0000-4000-8000-000000000001']);

        return S::rows(1);
    });
    app()->instance($source::class, $source);
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => $source::class]]);

    app(FreshRunProcessor::class)->run(crawler: $crawler, run: $run);

    expect(Procurement::query()->count())->toBe(0)
        ->and($run->fresh()->execution_owner_token)->toBe('00000000-0000-4000-8000-000000000001')
        ->and($run->fresh()->cursor['blockFrom'])->toBe(1);
});

test('сбой очереди не теряет автоматическое возобновление прогона', function (): void {
    $crawler = Crawler::factory()->create();
    SourceCircuit::factory()->create(['platform_id' => $crawler->registry->platform_id, 'retry_at' => now()->subMinute()]);
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'stats' => [
            'source_suspended' => true,
            'execution_context' => ['mode' => 'fresh', 'execution_lane' => 'standard', 'from' => null, 'to' => null],
        ],
    ]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
    try {
        app(SuspendedRunResumer::class)->resumeDue();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Redis unavailable');
    }

    expect($run->fresh()->stats->get('source_suspended'))->toBeTrue();
    Bus::swap(app(Dispatcher::class));
    $this->travel(11)->minutes(); // TTL unique-lock после неудачного dispatch — 600 секунд.
    app(SuspendedRunResumer::class)->resumeDue();
    Queue::assertPushed(CrawlRunJob::class);
});

test('устаревший тик не снимает новое ожидание брейкера', function (): void {
    $circuit = SourceCircuit::factory()->create(['retry_at' => now()->subMinute()]);
    $newRetryAt = now()->addMinutes(10)->startOfSecond();
    $circuit->fresh()->update(['retry_at' => $newRetryAt]);

    app(SourceCircuitBreaker::class)->finishWaiting($circuit);

    expect($circuit->fresh()->retry_at->eq($newRetryAt))->toBeTrue();
});

test('повторная job при активном владельце завершается без ошибки и запросов', function (): void {
    $crawler = Crawler::factory()->create();
    $runs = app(CrawlerRunManager::class);
    $run = $runs->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
    $runs->recordExecutionContext(run: $run, crawler: $crawler, mode: CrawlerMode::Fresh, executionLane: $crawler->execution_lane, from: null, to: null);
    $runs->acquireExecutionLease(run: $run);
    $owner = $run->execution_owner_token;
    $source = S::install(new ScriptedSourceMethod);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    expect($source->requestedBlocks)->toBe([])
        ->and($run->fresh()->execution_owner_token)->toBe($owner);
    Queue::assertNothingPushed();
});
