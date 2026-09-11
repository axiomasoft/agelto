<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        'crawler.eis.block_size' => 2,
        // Нулевой оверлап — этот тест проверяет делегирование команды, не окно перекрытия (П-2,
        // `tests/Feature/Crawler/BacklogRescanProcessorTest.php` несёт отдельную пробу сдвига).
        'crawler.tracking.window_overlap_days' => 0,
    ]);
});

test('делегирует BacklogRescanProcessor и репортит число обработанных прогонов', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);

    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => (new ListingCursor(
            recoveryScope: RecoveryScope::fromFreshCrawler(crawler: $freshCrawler)->toArray(),
        ))->toArray(),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => ['from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-01T12:00:00Z']],
    ]);

    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: [new ListingBatch(
        rows: [],
        rawBody: '',
        dedupSignature: null,
        rowsTotal: 0,
        httpStatus: 200,
        durationMs: 0,
        requestParams: [],
    )]));
    config(['crawler.platforms.eis.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:rescan-backlog')
        ->expectsOutputToContain('обработано прогонов — 1')
        ->assertExitCode(Command::SUCCESS);
});

test('нет прогонов с cap_hit — репортит ноль', function () {
    $this->artisan('crawler:rescan-backlog')
        ->expectsOutputToContain('обработано прогонов — 0')
        ->assertExitCode(Command::SUCCESS);
});
