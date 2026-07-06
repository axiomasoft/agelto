<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Enums\Procurement\CrawlerMode;
use App\Enums\Procurement\RunStatus;
use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Modes\BacklogRescanProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * Один «частичный» блок (`rowsTotal < block_size=2`) — сразу дренирует update-окно
 * за один день, без ценового дробления.
 */
function backlogRescanDrainedBatch(): ListingBatch
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

function useBacklogRescanQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
    ]);
});

test('cap_hit с живым archive-профилем реестра — scan вызван по update-окну, флаг помечен', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([backlogRescanDrainedBatch()]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(1);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Completed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'])->not->toBeNull();
});

test('повторный sweep() уже помеченный run не берёт', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => [
            'cap_hit' => true,
            'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z'],
            'backlog_rescanned_at' => '2024-01-02T00:00:00Z',
        ],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and(Run::query()->count())->toBe(1);
});

test('нет archive-профиля реестра — warning, флаг не помечен, scan не вызван', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0)
        ->and(Run::query()->count())->toBe(1);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('possible_skip_window.from не определён — пропуск без пометки', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => null, 'to' => '2024-01-01T12:00:00Z']],
    ]);

    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});

test('исключение внутри scan — archive-run failed, флаг остаётся непомеченным', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    $freshRun = Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    // Пустая очередь батчей — QueueSourceMethod бросит OutOfBoundsException при первом вызове.
    useBacklogRescanQueueSourceMethod([]);

    $processed = app(BacklogRescanProcessor::class)->sweep();

    expect($processed)->toBe(0);

    $archiveRun = Run::query()->where('crawler_id', '!=', $freshCrawler->id)->sole();
    expect($archiveRun->status)->toBe(RunStatus::Failed);

    $freshRun->refresh();
    expect($freshRun->stats['backlog_rescanned_at'] ?? null)->toBeNull();
});
