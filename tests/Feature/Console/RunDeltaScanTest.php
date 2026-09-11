<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Console\Command;

test('отдельно сообщает busy, когда delta-run уже исполняется другим owner', function (): void {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create([
        'registry_id' => $registry->id,
        'mode' => CrawlerMode::Fresh,
    ]);
    $archiveCrawler = Crawler::factory()->create([
        'registry_id' => $registry->id,
        'mode' => CrawlerMode::Archive,
        'is_active' => true,
    ]);
    $run = Run::factory()->for($archiveCrawler)->create([
        'purpose' => RunPurpose::Delta,
        'subject_key' => "fresh-crawler:{$freshCrawler->id}",
    ]);

    expect(app(CrawlerRunManager::class)->acquireExecutionLease(run: $run))->toBeTrue();

    $this->artisan(command: "crawler:run-delta {$freshCrawler->id}")
        ->expectsOutputToContain('delta-scan уже исполняется другим owner, пропущен.')
        ->assertExitCode(Command::SUCCESS);
});
