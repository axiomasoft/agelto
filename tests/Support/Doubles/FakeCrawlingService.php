<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Dto\Crawling\CrawlingItemDto;
use App\Dto\Crawling\PageMetaDto;
use App\Interfaces\Crawling\BrowserClient;
use App\Models\Crawler\Crawler;
use App\Process\Crawling\AbstractCrawlingService;
use App\Process\Crawling\Run\CrawlerRunManager;
use App\Repositories\Crawling\CrawlingRepository;
use App\Services\AppLogger;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use LogicException;
use RuntimeException;

use function fakeBrowserClient;

/**
 * Краулер без реального браузера: одна пустая страница, без следующей (без usleep-цикла).
 * Abstract-контракт AbstractCrawlingService реализован заглушками; на пустой странице
 * (getNodes → []) ветка crawlingItem() не вызывается.
 */
final class FakeCrawlingService extends AbstractCrawlingService
{
    private bool $shouldThrow = false;

    /**
     * Готовый сервис с фейковым браузером. Можно передать свой мок —
     * например строгий, чтобы доказать, что браузер не трогается.
     */
    public static function make(?BrowserClient $browser = null): self
    {
        return new self(
            app(AppLogger::class),
            $browser ?? fakeBrowserClient(),
            app(CrawlingRepository::class),
            app(CrawlerRunManager::class),
        );
    }

    /** Заставляет загрузку страницы упасть — для проверки фиксации падения прогона. */
    public function throwing(): self
    {
        $this->shouldThrow = true;

        return $this;
    }

    public function getNodes(string $html): array
    {
        return [];
    }

    public function crawlingItem(mixed $node): CrawlingItemDto
    {
        throw new LogicException('Не вызывается: страница пустая (getNodes → []).');
    }

    public function getPageMeta(string $html): PageMetaDto
    {
        return new PageMetaDto(current_page: 1, has_next_page: false);
    }

    public function getCrawlerUrl(Crawler $crawler): string
    {
        return 'https://example.test/?pageNumber=1';
    }

    public function pageNumberKey(Crawler $crawler): string
    {
        return 'pageNumber';
    }

    protected function parseAttachments(string $commonInfoUrl): array
    {
        return [];
    }

    protected function fetchPage(string $requestUrl): Response
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('boom');
        }

        return new Response(new PsrResponse(200, [], '<html></html>'));
    }
}
