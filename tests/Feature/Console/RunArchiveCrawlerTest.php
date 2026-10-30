<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

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
    config(['crawler.platforms.eis.block_size' => 2]);
    // P4.7 явно маршрутизирует job на connection `redis`; в тесте сохраняем синхронный driver,
    // чтобы проверять команду и доменный результат без запущенного Redis worker.
    config(['queue.connections.redis.driver' => 'sync']);
});

beforeEach(fn () => LegacyBusinessNetwork::install());

test('резолвит профиль по числовому id и диспатчит CrawlRunJob(mode: Archive) с окном from/to', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);
    Queue::fake();

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id, '--from' => '2024-01-01', '--to' => '2024-01-01'])
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(CrawlRunJob::class, function (CrawlRunJob $job) use ($crawler): bool {
        return $job->crawlerId === $crawler->id
            && $job->mode === CrawlerMode::Archive
            && str_starts_with((string) $job->from, '2024-01-01')
            && str_starts_with((string) $job->to, '2024-01-01');
    });
});

test('без faked-очереди job исполняется синхронно (sync-driver) и доводит archive-прогон до completed', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(repeatStableWindows: true, batches: [runArchiveCrawlerDrainedBatch()]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id, '--from' => '2024-01-01', '--to' => '2024-01-01'])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('резолвит профиль по name', function () {
    $crawler = Crawler::factory()->create(['name' => 'eis-archive-profile', 'mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(repeatStableWindows: true, batches: [runArchiveCrawlerDrainedBatch()]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

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

test('без --from профиль БЕЗ окна — FAILURE без запуска прогона (P3.8, D113)', function () {
    $crawler = Crawler::factory()
        ->filter(new EisSearchFilter(okpd2Codes: ['43']))
        ->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id])
        ->expectsOutputToContain('не заполнено окно дат')
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->count())->toBe(0);
});

test('без --from профиль С окном берёт окно профиля (P3.8, D113)', function () {
    $crawler = Crawler::factory()
        ->filter(new EisSearchFilter(
            okpd2Codes: ['43'],
            publishedFrom: CarbonImmutable::parse('2025-01-01'),
            publishedTo: CarbonImmutable::parse('2025-12-31'),
        ))
        ->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);
    Queue::fake();

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id])
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && str_starts_with((string) $job->from, '2025-01-01')
        && str_starts_with((string) $job->to, '2025-12-31'));
});

test('--from/--to перекрывают окно профиля (P3.8, D113)', function () {
    $crawler = Crawler::factory()
        ->filter(new EisSearchFilter(
            okpd2Codes: ['43'],
            publishedFrom: CarbonImmutable::parse('2025-01-01'),
            publishedTo: CarbonImmutable::parse('2025-12-31'),
        ))
        ->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);
    Queue::fake();

    $this->artisan('crawler:run-archive', ['crawler' => (string) $crawler->id, '--from' => '2024-03-01', '--to' => '2024-03-31'])
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && str_starts_with((string) $job->from, '2024-03-01')
        && str_starts_with((string) $job->to, '2024-03-31'));
});
