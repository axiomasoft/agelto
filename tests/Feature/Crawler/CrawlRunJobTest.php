<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * «Полностью известный»/«дренированный» блок — самый быстрый способ довести прогон до
 * `completed` за одну итерацию (тот же приём, что `RunFreshCrawlerTest`/`RunArchiveCrawlerTest`).
 */
function crawlRunJobEmptyBatch(): ListingBatch
{
    return new ListingBatch(rows: [], rawBody: '', dedupSignature: null, rowsTotal: 0, httpStatus: 200, durationMs: 0, requestParams: []);
}

/** @return array<string, string> */
function crawlRunJobCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'Начальная (максимальная) цена контракта' => '1 250 000,50',
        'Валюта' => 'RUB',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование Заказчика' => 'ГБУ «Тестовое учреждение»',
    ];
}

function crawlRunJobBatch(array $rows, string $dedupSignature, string $from): ListingBatch
{
    return new ListingBatch(
        rows: $rows,
        rawBody: 'fresh-csv-'.$from,
        dedupSignature: $dedupSignature,
        rowsTotal: count($rows),
        httpStatus: 200,
        durationMs: 0,
        requestParams: ['from' => $from],
    );
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
});

test('job резолвит fresh-режим и вызывает FreshRunProcessor', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [crawlRunJobEmptyBatch()]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    expect(Run::query()->where('crawler_id', $crawler->id)->sole()->status)->toBe(RunStatus::Completed);
});

test('job резолвит archive-режим и вызывает ArchiveRunProcessor за окно from/to', function () {
    config(['crawler.eis.block_size' => 2]);
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [crawlRunJobEmptyBatch()]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    app()->call([
        new CrawlRunJob(
            crawlerId: $crawler->id,
            mode: CrawlerMode::Archive,
            from: '2024-01-01',
            to: '2024-01-01',
        ),
        'handle',
    ]);

    expect(Run::query()->where('crawler_id', $crawler->id)->sole()->status)->toBe(RunStatus::Completed);
});

test('повторный диспатч на тот же crawlerId дедуплицируется unique-локом', function () {
    Queue::fake();

    CrawlRunJob::dispatch(crawlerId: 1, mode: CrawlerMode::Fresh);
    CrawlRunJob::dispatch(crawlerId: 1, mode: CrawlerMode::Fresh);

    Queue::assertPushed(CrawlRunJob::class, 1);
});

test('диспатч на РАЗНЫЕ crawlerId не делит unique-лок', function () {
    Queue::fake();

    CrawlRunJob::dispatch(crawlerId: 1, mode: CrawlerMode::Fresh);
    CrawlRunJob::dispatch(crawlerId: 2, mode: CrawlerMode::Fresh);

    Queue::assertPushed(CrawlRunJob::class, 2);
});

test('long lane задаёт отдельные connection и queue, а constructor без lane сохраняет standard', function (): void {
    $standard = new CrawlRunJob(crawlerId: 1, mode: CrawlerMode::Fresh);
    $long = new CrawlRunJob(crawlerId: 2, mode: CrawlerMode::Archive, executionLane: CrawlerExecutionLane::Long);

    expect($standard->executionLane)->toBe(CrawlerExecutionLane::Standard)
        ->and($standard->connection)->toBe('redis')
        ->and($standard->queue)->toBe('crawl')
        ->and($long->connection)->toBe('redis_crawl_long')
        ->and($long->queue)->toBe('crawl_long');
});

test('исчерпание бюджета checkpoint_seconds оставляет running и job самодиспатчит продолжение', function () {
    config(['crawler.run.checkpoint_seconds' => 0]);
    Queue::fake();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->stats['checkpoints'] ?? null)->toBe(1);

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->mode === CrawlerMode::Fresh
        && $job->executionLane === CrawlerExecutionLane::Standard
        && $job->connection === 'redis'
        && $job->queue === 'crawl');
});

test('fresh hard-cap самодиспатчит ровно одно продолжение и завершает его на следующем slice', function () {
    config(['crawler.fresh_max_blocks' => 1]);
    Queue::fake();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [
        crawlRunJobBatch([crawlRunJobCsvRow('0173200001234000090')], dedupSignature: 'cap-block', from: '1'),
        crawlRunJobEmptyBatch(),
    ]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $job = new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh);
    app()->call([$job, 'handle']);

    $checkpointed = Run::query()->where('crawler_id', $crawler->id)->sole();
    expect($checkpointed->status)->toBe(RunStatus::Running)
        ->and($checkpointed->cursor['blockFrom'])->toBe(501)
        ->and($checkpointed->stats['stop_reason'] ?? null)->toBe('fresh_max_blocks_checkpoint');
    Queue::assertPushed(CrawlRunJob::class, 1);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    expect($checkpointed->refresh()->status)->toBe(RunStatus::Completed)
        ->and($checkpointed->cursor['reachedKnown'])->toBeTrue()
        ->and($checkpointed->stats['stop_reason'] ?? null)->toBe('source_exhausted');
    Queue::assertPushed(CrawlRunJob::class, 1);
});

test('request-budget checkpoint archive сохраняет execution lane и самодиспатчит continuation', function (): void {
    Queue::fake();
    $sourceMethod = new class implements SourceMethod
    {
        public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
        {
            throw PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0);
        }

        public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
        {
            return new ProcurementDetails;
        }

        public function isAvailable(): bool
        {
            return true;
        }
    };
    app()->instance($sourceMethod::class, $sourceMethod);
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => $sourceMethod::class]]);

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive, 'source_method' => SourceMethodEnum::Csv]);

    app()->call([
        new CrawlRunJob(
            crawlerId: $crawler->id,
            mode: CrawlerMode::Archive,
            executionLane: CrawlerExecutionLane::Long,
            from: '2024-01-01',
            to: '2024-01-01',
        ),
        'handle',
    ]);

    $run = Run::query()->where('crawler_id', $crawler->id)->sole();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->cursor['day'])->toBe('2024-01-01')
        ->and($run->stats['checkpoints'] ?? null)->toBe(1)
        ->and($run->last_error)->toBeNull()
        ->and($run->finished_at)->toBeNull();

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->mode === CrawlerMode::Archive
        && $job->executionLane === CrawlerExecutionLane::Long
        && $job->connection === 'redis_crawl_long'
        && $job->queue === 'crawl_long');
});

test('self-dispatch continuation сохраняет long lane и оба маршрута', function (): void {
    config(['crawler.run.checkpoint_seconds' => 0]);
    Queue::fake();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);

    app()->call([
        new CrawlRunJob(
            crawlerId: $crawler->id,
            mode: CrawlerMode::Fresh,
            executionLane: CrawlerExecutionLane::Long,
        ),
        'handle',
    ]);

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->executionLane === CrawlerExecutionLane::Long
        && $job->connection === 'redis_crawl_long'
        && $job->queue === 'crawl_long');
});

test('сигнал Pause останавливает fresh-прогон между блоками с сохранённым курсором и stats.stop_reason', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'cursor' => (new ListingCursor(blockFrom: 501))->toArray(),
        'stop_requested_at' => Carbon::now(),
        'stop_kind' => RunStopKind::Pause,
    ]);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->cursor['blockFrom'])->toBe(501)
        ->and($run->stats['stop_reason'])->toContain('pause')
        ->and($run->stop_requested_at)->toBeNull();
});

test('сигнал Cancel даёт cancelled прогону', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh, 'source_method' => SourceMethodEnum::Csv]);
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'stop_requested_at' => Carbon::now(),
        'stop_kind' => RunStopKind::Cancel,
    ]);

    app()->call([new CrawlRunJob(crawlerId: $crawler->id, mode: CrawlerMode::Fresh), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->stats['stop_reason'])->toContain('cancel');
});

test('uniqueFor CrawlRunJob задаёт TTL unique-лока — без него SETNX на Redis держит лок вечно', function () {
    expect(app(CrawlRunJob::class, ['crawlerId' => 1, 'mode' => CrawlerMode::Fresh])->uniqueFor())->toBeGreaterThan(0);
});
