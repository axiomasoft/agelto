<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Crawler\DeltaScanOutcome;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\DocumentDownloader;
use App\Services\Procurement\Modes\ArchiveRunProcessor;
use App\Services\Procurement\Modes\BacklogRescanProcessor;
use App\Services\Procurement\Modes\DeltaScanProcessor;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\PlatformRequestBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

final class ExhaustedPlatformBudgetSourceMethod implements SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        throw PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0);
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        throw PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0);
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

function useExhaustedPlatformBudgetSourceMethod(): void
{
    app()->instance(ExhaustedPlatformBudgetSourceMethod::class, new ExhaustedPlatformBudgetSourceMethod);
    config(['crawler.platforms.eis.source_methods.'.SourceMethodEnum::Csv->value => ExhaustedPlatformBudgetSourceMethod::class]);
}

test('исчерпание бюджета листинга завершает срез чекпоинтом без падения прогона', function (): void {
    Sleep::fake();
    useExhaustedPlatformBudgetSourceMethod();
    $crawler = Crawler::factory()->create([
        'source_method' => SourceMethodEnum::Csv,
        'fallback_method' => null,
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->refresh()->status)->toBe(RunStatus::Running)
        ->and($run->stats['checkpoints'] ?? null)->toBe(1)
        ->and($run->last_error)->toBeNull();
});

test('исчерпание бюджета archive сохраняет недренированный день и не переводит run в failed', function (): void {
    Sleep::fake();
    useExhaustedPlatformBudgetSourceMethod();
    $crawler = Crawler::factory()->create([
        'mode' => CrawlerMode::Archive,
        'source_method' => SourceMethodEnum::Csv,
        'fallback_method' => null,
    ]);

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-01'),
    );

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->cursor['day'])->toBe('2024-01-01')
        ->and($run->stats['checkpoints'] ?? null)->toBe(1)
        ->and($run->last_error)->toBeNull()
        ->and($run->finished_at)->toBeNull();
});

test('исчерпание бюджета delta сохраняет недренированный день до следующего cron', function (): void {
    Sleep::fake();
    Carbon::setTestNow(Carbon::parse('2024-01-01 12:00:00'));
    config(['crawler.tracking.window_overlap_days' => 0]);
    useExhaustedPlatformBudgetSourceMethod();

    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create([
        'registry_id' => $registry->id,
        'mode' => CrawlerMode::Archive,
        'is_active' => true,
        'source_method' => SourceMethodEnum::Csv,
        'fallback_method' => null,
    ]);

    $result = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);
    $run = $result->run;

    expect($result->outcome)->toBe(DeltaScanOutcome::Processed)
        ->and($run)->not->toBeNull()
        ->and($run?->fresh()->status)->toBe(RunStatus::Running)
        ->and($run?->fresh()->cursor['day'])->toBe('2024-01-01')
        ->and($run?->fresh()->stats['checkpoints'] ?? null)->toBe(1)
        ->and($run?->fresh()->last_error)->toBeNull()
        ->and($run?->fresh()->finished_at)->toBeNull();

    Carbon::setTestNow();
});

test('исчерпание бюджета backlog не помечает fresh-run rescanned', function (): void {
    Sleep::fake();
    config(['crawler.tracking.window_overlap_days' => 0]);
    useExhaustedPlatformBudgetSourceMethod();

    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create([
        'registry_id' => $registry->id,
        'mode' => CrawlerMode::Archive,
        'is_active' => true,
        'source_method' => SourceMethodEnum::Csv,
        'fallback_method' => null,
    ]);
    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => (new ListingCursor(
            recoveryScope: RecoveryScope::fromFreshCrawler(crawler: $freshCrawler)->toArray(),
        ))->toArray(),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $archiveRun = Run::query()->where('crawler_id', $archiveCrawler->id)->sole();
    $freshRun->refresh();

    expect($archiveRun->status)->toBe(RunStatus::Running)
        ->and($archiveRun->cursor['day'])->toBe('2024-01-01')
        ->and($archiveRun->stats['checkpoints'] ?? null)->toBe(1)
        ->and($archiveRun->last_error)->toBeNull()
        ->and($archiveRun->finished_at)->toBeNull()
        ->and($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('исчерпание бюджета карточки не растит detail_attempts и не двигает retry', function (): void {
    Sleep::fake();
    useExhaustedPlatformBudgetSourceMethod();
    $procurement = Procurement::factory()->create([
        'detail_attempts' => 2,
        'detail_next_retry_at' => null,
        'detail_last_error' => null,
    ]);

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($procurement->detail_attempts)->toBe(2)
        ->and($procurement->detail_next_retry_at)->toBeNull()
        ->and($procurement->detail_last_error)->toBeNull()
        ->and($procurement->detail_fetched_at)->toBeNull();
});

test('исчерпание бюджета документа не записывает error-блок документа', function (): void {
    Sleep::fake();
    $platform = Platform::factory()->create([
        'base_url' => 'https://eis.test/',
        'throttle' => [
            'requestsPerMinuteBase' => 1,
            'requestsPerMinutePerProxy' => 0,
            'maxWaitSeconds' => 0,
            'stages' => [
                'document' => ['minPauseMs' => 0, 'maxPauseMs' => 0],
            ],
        ],
    ]);
    $document = Document::factory()->for($platform)->create([
        'source_url' => 'https://eis.test/filestore/file.html?uid=budget',
        'attempts' => 2,
    ]);
    Http::fake();

    app(PlatformRequestBudget::class)->acquire(host: 'eis.test', stage: CrawlStage::Document);
    $version = app(DocumentDownloader::class)->download(document: $document);

    $document->refresh();

    expect($version)->toBeNull()
        ->and($document->attempts)->toBe(2)
        ->and($document->last_error)->toBeNull()
        ->and($document->next_retry_at)->toBeNull();

    Http::assertNothingSent();
});
