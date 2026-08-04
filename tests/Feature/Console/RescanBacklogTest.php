<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
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
    config(['crawler.eis.block_size' => 2]);
});

test('делегирует BacklogRescanProcessor и репортит число обработанных прогонов', function () {
    $registry = Registry::factory()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true, 'source_method' => SourceMethodEnum::Csv]);

    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
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
    config(['crawler.source_methods' => [SourceMethodEnum::Csv->value => QueueSourceMethod::class]]);

    $this->artisan('crawler:rescan-backlog')
        ->expectsOutputToContain('обработано прогонов — 1')
        ->assertExitCode(Command::SUCCESS);
});

test('нет прогонов с cap_hit — репортит ноль', function () {
    $this->artisan('crawler:rescan-backlog')
        ->expectsOutputToContain('обработано прогонов — 0')
        ->assertExitCode(Command::SUCCESS);
});
