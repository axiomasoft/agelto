<?php

declare(strict_types=1);

use App\Dto\Crawling\CrawlingItemDto;
use App\Dto\Crawling\PageMetaDto;
use App\Enums\Crawler\CrawlerRunStatus;
use App\Interfaces\Crawling\BrowserClient;
use App\Models\Crawler\Crawler as CrawlerModel;
use App\Models\Crawler\CrawlerRun;
use App\Process\Crawling\AbstractCrawlingService;
use App\Process\Crawling\Run\CrawlerRunManager;
use App\Repositories\Crawling\CrawlingRepository;
use App\Services\AppLogger;
use Sys\Models\SysLog;

/**
 * Тестовый краулер без реального браузера: одна страница, без следующей (без usleep-цикла).
 */
final class FakeCrawlingService extends AbstractCrawlingService
{
    public bool $shouldThrow = false;

    public function getNodes(string $html): array
    {
        return [];
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        throw new LogicException('не используется в тесте');
    }

    public function getPageMeta(string $html): PageMetaDto
    {
        return new PageMetaDto(current_page: 1, has_next_page: false);
    }

    public function getCrawlerUrl(CrawlerModel $crawler): string
    {
        return 'https://example.test/?pageNumber=1';
    }

    public function pageNumberKey(CrawlerModel $crawler): string
    {
        return 'pageNumber';
    }

    protected function parseAttachments(string $commonInfoUrl): array
    {
        return [];
    }

    protected function fetchPage(string $requestUrl): Illuminate\Http\Client\Response
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('boom');
        }

        return new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(200, [], '<html></html>'));
    }
}

function fakeCrawlingService(bool $shouldThrow = false): FakeCrawlingService
{
    $browser = Mockery::mock(BrowserClient::class);
    $browser->shouldReceive('withRandomProxy')->andReturnSelf();
    $browser->shouldReceive('reportSuccess')->andReturnNull();
    $browser->shouldReceive('reportFail')->andReturnNull();
    $browser->shouldReceive('close')->andReturnNull();

    $service = new FakeCrawlingService(
        app(AppLogger::class),
        $browser,
        app(CrawlingRepository::class),
        app(CrawlerRunManager::class),
    );
    $service->shouldThrow = $shouldThrow;

    return $service;
}

it('создаёт прогон, бьёт heartbeat и штатно завершает его', function () {
    $crawler = crawlerProfile();

    fakeCrawlingService()->start($crawler);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->last_heartbeat_at)->not->toBeNull();

    expect(SysLog::query()->where('entity_id', $run->id)->where('message', 'like', '%Старт прогона%')->exists())->toBeTrue();
    expect(SysLog::query()->where('entity_id', $run->id)->where('message', 'like', '%завершён%')->exists())->toBeTrue();
});

it('фиксирует падение прогона и сохраняет страницу курсора', function () {
    $crawler = crawlerProfile();

    expect(fn () => fakeCrawlingService(shouldThrow: true)->start($crawler))
        ->toThrow(RuntimeException::class);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Failed)
        ->and($run->last_error)->toBe('boom')
        ->and($run->attempts)->toBe(1)
        ->and($run->cursor_url)->not->toBeNull() // heartbeat записал страницу до падения
        ->and($run->current_page)->toBe(1);

    expect(SysLog::query()->where('entity_id', $run->id)->where('message', 'like', '%упал%')->exists())->toBeTrue();
});

it('не запускает второй прогон, если по профилю уже идёт живой', function () {
    $crawler = crawlerProfile();

    CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'started_at' => now(),
        'last_heartbeat_at' => now(), // свежий heartbeat — прогон жив
    ]);

    $browser = Mockery::mock(BrowserClient::class);
    $browser->shouldNotReceive('withRandomProxy'); // ранний выход — браузер не трогаем

    $service = new FakeCrawlingService(
        app(AppLogger::class),
        $browser,
        app(CrawlingRepository::class),
        app(CrawlerRunManager::class),
    );

    $service->start($crawler);

    expect(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1);
});
