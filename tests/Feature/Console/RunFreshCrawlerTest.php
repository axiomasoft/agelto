<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\Crawler\BrokenSourceMethod;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * Один «полностью известный» блок (rowsNew=0, rowsUpdated=0) — самый быстрый способ
 * довести `FreshRunProcessor::run()` до `completed` за одну итерацию.
 */
function runFreshCrawlerKnownBatch(): ListingBatch
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
    Storage::fake('s3');
});

test('резолвит профиль по числовому id и диспатчит CrawlRunJob(mode: Fresh)', function () {
    $crawler = Crawler::factory()->create(['source_method' => SourceMethodEnum::Csv]);
    Queue::fake();

    $this->artisan('crawler:run-fresh', ['crawler' => (string) $crawler->id])
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id && $job->mode === CrawlerMode::Fresh);
});

test('без faked-очереди job исполняется синхронно (sync-driver) и доводит прогон до completed', function () {
    $crawler = Crawler::factory()->create(['source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runFreshCrawlerKnownBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-fresh', ['crawler' => (string) $crawler->id])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('резолвит профиль по name и диспатчит CrawlRunJob', function () {
    $crawler = Crawler::factory()->create(['name' => 'eis-fresh-profile', 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runFreshCrawlerKnownBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-fresh', ['crawler' => 'eis-fresh-profile'])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('несуществующий id или name профиля — команда падает с FAILURE и сообщением об ошибке', function () {
    $this->artisan('crawler:run-fresh', ['crawler' => '999999'])
        ->expectsOutputToContain('Нет ни одного подходящего профиля краулера для fresh-скана.')
        ->assertExitCode(Command::FAILURE);

    $this->artisan('crawler:run-fresh', ['crawler' => 'no-such-profile'])
        ->expectsOutputToContain('Нет ни одного подходящего профиля краулера для fresh-скана.')
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->count())->toBe(0);
});

test('без аргумента резолвит все активные fresh-профили, но не архивные и не выключенные', function () {
    $first = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);
    $second = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);
    $archived = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);
    $inactive = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => false, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [
        runFreshCrawlerKnownBatch(),
        runFreshCrawlerKnownBatch(),
    ]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-fresh')
        ->assertExitCode(Command::SUCCESS);

    expect(Run::query()->count())->toBe(2)
        ->and(Run::query()->where('crawler_id', $first->id)->sole()->status)->toBe(RunStatus::Completed)
        ->and(Run::query()->where('crawler_id', $second->id)->sole()->status)->toBe(RunStatus::Completed)
        ->and(Run::query()->where('crawler_id', $archived->id)->exists())->toBeFalse()
        ->and(Run::query()->where('crawler_id', $inactive->id)->exists())->toBeFalse();
});

test('падение одного профиля не прерывает обработку остальных, но команда возвращает FAILURE', function () {
    $healthy = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);
    $broken = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Html]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runFreshCrawlerKnownBatch()]));
    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
        SourceMethodEnum::Html->value => BrokenSourceMethod::class,
    ]]);

    $this->artisan('crawler:run-fresh')
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->where('crawler_id', $healthy->id)->sole()->status)->toBe(RunStatus::Completed)
        ->and(Run::query()->where('crawler_id', $broken->id)->sole()->status)->toBe(RunStatus::Failed);
});

test('активный прогон профиля — команда отказывает, второй job не ставится', function () {
    $crawler = Crawler::factory()->create(['source_method' => SourceMethodEnum::Csv]);
    $activeRun = Run::factory()->for($crawler, 'crawler')->create(['status' => RunStatus::Running]);
    Queue::fake();

    $this->artisan('crawler:run-fresh', ['crawler' => (string) $crawler->id])
        ->expectsOutputToContain("уже идёт прогон #{$activeRun->id}")
        ->assertExitCode(Command::FAILURE);

    Queue::assertNotPushed(CrawlRunJob::class);
    expect(Run::query()->where('crawler_id', $crawler->id)->count())->toBe(1);
});

test('мульти-профильный вызов: активный прогон одного профиля не мешает запуску свободного', function () {
    $busy = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);
    $activeRun = Run::factory()->for($busy, 'crawler')->create(['status' => RunStatus::Running]);
    $free = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runFreshCrawlerKnownBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-fresh')
        ->expectsOutputToContain("уже идёт прогон #{$activeRun->id}")
        ->assertExitCode(Command::FAILURE);

    expect(Run::query()->where('crawler_id', $busy->id)->count())->toBe(1)
        ->and(Run::query()->where('crawler_id', $free->id)->sole()->status)->toBe(RunStatus::Completed);
});
