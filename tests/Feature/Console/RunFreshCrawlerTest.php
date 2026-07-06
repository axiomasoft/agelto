<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Enums\Procurement\CrawlerMode;
use App\Enums\Procurement\RunStatus;
use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler;
use App\Models\Procurement\Run;
use Illuminate\Console\Command;
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

test('резолвит профиль по числовому id и делегирует FreshRunProcessor', function () {
    $crawler = Crawler::factory()->create(['source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [runFreshCrawlerKnownBatch()]));
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:run-fresh', ['crawler' => (string) $crawler->id])
        ->assertExitCode(Command::SUCCESS);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();

    expect($run->status)->toBe(RunStatus::Completed);
});

test('резолвит профиль по name и делегирует FreshRunProcessor', function () {
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
