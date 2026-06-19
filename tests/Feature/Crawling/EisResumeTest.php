<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Interfaces\Crawling\BrowserClient;
use App\Models\Crawler\CrawlerRun;
use App\Process\Crawling\Run\CrawlerRunManager;
use App\Repositories\Crawling\CrawlingRepository;
use App\Services\AppLogger;
use Eis\Crawling\EisCrawlingService;
use HeadlessChromium\Page;
use Sys\Models\SysLog;

/**
 * Браузер, который на любой openPage отдаёт страницу с «0 записей» —
 * priceSplitHook сразу выходит, до пагинации/usleep дело не доходит.
 */
function eisServiceWithEmptyResults(): EisCrawlingService
{
    $page = Mockery::mock(Page::class);
    $page->shouldReceive('getHtml')->andReturn('<div class="search-results__total">0</div>');

    $browser = Mockery::mock(BrowserClient::class);
    $browser->shouldReceive('withRandomProxy')->andReturnSelf();
    $browser->shouldReceive('openPage')->andReturn($page);
    $browser->shouldReceive('reportSuccess')->andReturnNull();
    $browser->shouldReceive('reportFail')->andReturnNull();
    $browser->shouldReceive('close')->andReturnNull();

    return new EisCrawlingService(
        app(AppLogger::class),
        $browser,
        app(CrawlingRepository::class),
        app(CrawlerRunManager::class),
    );
}

it('архивный режим возобновляется с сохранённого дня и логирует это', function () {
    $crawler = crawlerProfile([
        'filter' => ['publishDateFrom' => '2025-01-01', 'publishDateTo' => '2025-01-05'],
    ]);

    // Оборванный прогон с сохранённым днём внутри окна — recoverOrphan сохранит день.
    CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now()->subHour(),
        'last_heartbeat_at' => now()->subHour(),
        'day_from' => '2025-01-03',
    ]);

    eisServiceWithEmptyResults()->start($crawler);

    expect(SysLog::query()
        ->where('message', 'like', '%возобновление с дня 2025-01-03%')
        ->exists())->toBeTrue();
});

it('оперативный режим сбрасывает курсор и идёт полным проходом', function () {
    $crawler = crawlerProfile(['filter' => []]); // нет дат → оперативный режим

    CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now()->subHour(),
        'last_heartbeat_at' => now()->subHour(),
        'cursor_url' => 'https://old/?pageNumber=9',
        'current_page' => 9,
    ]);

    eisServiceWithEmptyResults()->start($crawler);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->cursor_url)->toBeNull();

    expect(SysLog::query()
        ->where('message', 'like', '%Оперативный режим%')
        ->exists())->toBeTrue();
});
