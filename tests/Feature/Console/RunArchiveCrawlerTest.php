<?php

declare(strict_types=1);

use App\Dto\Crawler\ListingBatch;
use App\Enums\Crawler\CrawlerMode;
use App\Enums\Crawler\RunStatus;
use App\Enums\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * Один «частичный» блок (`rowsTotal < block_size=2`) — самый быстрый способ довести
 * `ArchiveRunProcessor::run()` до `completed` за один день.
 */
function runArchiveCrawlerDrainedBatch(): ListingBatch
{
    return new ListingBatch(
        rows: [],
        rawBody: '',
        dedupSignature: null,
        rowsTotal: 0,
        httpStatus: 200,
        durationMs: 0,
        requestParams: [],
    );
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config(['crawler.eis.block_size' => 2]);
});

test('резолвит профиль по числовому id и делегирует ArchiveRunProcessor за период', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runArchiveCrawlerDrainedBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id, '--from' => '2024-01-01', '--to' => '2024-01-01'])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('резолвит профиль по name', function () {
    $crawler = Crawler::factory()->create(['name' => 'eis-archive-profile', 'mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runArchiveCrawlerDrainedBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-archive', ['crawler' => 'eis-archive-profile', '--from' => '2024-01-01', '--to' => '2024-01-01'])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('несуществующий id/name профиля — FAILURE без запуска прогона', function () {
    $this->artisan('crawler:run-archive', ['crawler' => '999999', '--from' => '2024-01-01'])
        ->expectsOutputToContain('Не найден профиль краулера с таким id/name.')
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->count())->toBe(0);
});

test('без --from — FAILURE без запуска прогона', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id])
        ->expectsOutputToContain('Обязателен параметр --from')
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->count())->toBe(0);
});
