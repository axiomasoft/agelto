<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\DocumentDownloader;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\PlatformRequestBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    config(['crawler.source_methods.'.SourceMethodEnum::Csv->value => ExhaustedPlatformBudgetSourceMethod::class]);
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
