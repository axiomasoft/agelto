<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\DeltaScanProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * П-3 (`research/05-change-tracking.md` §3.1): плановый delta-scan окна
 * `[сегодня − window_overlap_days; сегодня]` по дате обновления — переиспользует
 * `ArchiveWindowScanner` тем же способом, что `BacklogRescanProcessor`, профиль резолвится
 * так же (активный archive-профиль реестра).
 *
 * @return array<string, string>
 */
function deltaScanCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
    ];
}

function deltaScanBatch(string $registryNumber): ListingBatch
{
    return new ListingBatch(
        rows: [deltaScanCsvRow($registryNumber)],
        rawBody: "raw csv body for {$registryNumber}",
        dedupSignature: "sig-{$registryNumber}",
        rowsTotal: 1,
        httpStatus: 200,
        durationMs: 5,
        requestParams: [],
    );
}

function useDeltaScanQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00'));
    config([
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 1_000_000_000,
        'crawler.tracking.window_overlap_days' => 2,
    ]);
});

test('нет активного archive-профиля реестра — warning, run не создаётся', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);

    Log::spy();

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($run)->toBeNull()
        ->and(Run::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'no active archive profile'))
        ->once();
});

test('активный archive-профиль реестра — дренирует окно [сегодня − overlap; сегодня] по update, run завершён', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    $archiveCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    // window_overlap_days=2, "сегодня"=2026-08-05 → окно [2026-08-03; 2026-08-05] — 3 дня.
    useDeltaScanQueueSourceMethod([
        deltaScanBatch('p46-delta-day-1'),
        deltaScanBatch('p46-delta-day-2'),
        deltaScanBatch('p46-delta-day-3'),
    ]);

    $run = app(DeltaScanProcessor::class)->run(crawler: $freshCrawler);

    expect($run)->not->toBeNull()
        ->and($run->crawler_id)->toBe($archiveCrawler->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(3);
});
