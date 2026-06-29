<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use App\Process\Crawling\Run\CrawlerRunManager;
use App\Repositories\Crawling\CrawlingRepository;
use App\Services\AppLogger;
use Eis\Crawling\EisCrawlingService;
use HeadlessChromium\Page;

/**
 * EIS-сервис с браузером, отдающим заданный HTML на любой openPage.
 * По умолчанию — страница «0 записей»: priceSplitHook сразу выходит, до пагинации/usleep не доходит.
 */
function fakeEisCrawlingService(string $html = '<div class="search-results__total">0</div>'): EisCrawlingService
{
    $page = Mockery::mock(Page::class);
    $page->shouldReceive('getHtml')->andReturn($html);

    $browser = fakeBrowserClient();
    $browser->shouldReceive('openPage')->andReturn($page);

    return new EisCrawlingService(
        app(AppLogger::class),
        $browser,
        app(CrawlingRepository::class),
        app(CrawlerRunManager::class),
    );
}

test('архивный режим возобновляется с сохранённого дня и логирует это', function () {
    $crawler = Crawler::factory()->create([
        'filter' => ['publishDateFrom' => '2025-01-01', 'publishDateTo' => '2025-01-05'],
    ]);

    // Оборванный прогон с сохранённым днём внутри окна — recoverOrphan сохранит день.
    CrawlerRun::factory()->for($crawler)->orphaned()->create([
        'day_from' => '2025-01-03',
    ]);

    fakeEisCrawlingService()->start($crawler);

    expect(sysLogExists('%возобновление с дня 2025-01-03%'))->toBeTrue();
});

test('оперативный режим сбрасывает курсор и идёт полным проходом', function () {
    $crawler = Crawler::factory()->create(['filter' => []]); // нет дат → оперативный режим

    CrawlerRun::factory()->for($crawler)->orphaned()->create([
        'cursor_url' => 'https://old/?pageNumber=9',
        'current_page' => 9,
    ]);

    fakeEisCrawlingService()->start($crawler);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->cursor_url)->toBeNull();

    expect(sysLogExists('%Оперативный режим%'))->toBeTrue();
});
