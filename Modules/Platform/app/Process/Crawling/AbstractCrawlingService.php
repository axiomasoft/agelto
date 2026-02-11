<?php

declare(strict_types=1);

namespace Platform\Process\Crawling;

use Carbon\Carbon;
use Throwable;
use App\Enums\LogOutput;
use App\Services\AppLogger;
use App\Services\BrowserService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\LinkDto;
use Platform\Dto\Crawling\PageResultDto;
use Platform\Interfaces\Crawling\PlatformCrawling;
use Platform\Models\Crawling\Crawler as CrawlerModel;
use Platform\Models\Crawling\IgnoreLinks;
use Platform\Models\Platform;
use Platform\Repositories\Crawling\CrawlingRepository;
use RuntimeException;

abstract class AbstractCrawlingService implements PlatformCrawling
{
    protected ?CrawlerModel $crawler = null;

    public function __construct(
        protected readonly AppLogger $logger,
        protected readonly BrowserService $browser,
    ) {}

    /**
     * Точка входа для запуска.
     */
    public function execute(CrawlerModel $crawler): void
    {
        try {
            // 1. Проверка VPN (только для local)
            $this->checkVpnConstraint();

            $this->logger->info("Инициализация браузера и прокси...", LogOutput::Console);
            $this->browser->withRandomProxy();

            // 2. Запуск основного цикла
            $this->run($crawler);

            $this->browser->reportSuccess();
        } catch (Throwable $e) {
            $this->logger->error("Ошибка краулера " . static::class . ": " . $e->getMessage());
            $this->browser->reportFail($e);
            throw $e;
        } finally {
            $this->browser->close();
        }
    }

    /**
     * Проверка: если LOCAL, то внешний IP должен совпадать с MY_REAL_IP из .env
     */
    protected function checkVpnConstraint(): void
    {
        /*if (App::environment('local')) {
            $this->logger->info("Проверка на отсутствие VPN...", LogOutput::Console);

            $realIp = env('MY_REAL_IP');
            if (!$realIp) {
                $this->logger->info("[WARNING] MY_REAL_IP не задан в .env. Пропуск проверки VPN.", LogOutput::Console);
                return;
            }

            try {
                // Прямой запрос без прокси для проверки текущего IP машины
                $currentIp = trim(Http::timeout(5)->get('https://api.ipify.org')->body());
                if ($currentIp !== (string)$realIp) {
                    throw new RuntimeException("VPN активен (IP: $currentIp). Отключите его для local режима.");
                }
                $this->logger->info("Проверка пройдена. Работаем без VPN.", LogOutput::Console);
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException) throw $e;
                $this->logger->info("[WARNING] Не удалось проверить IP: " . $e->getMessage(), LogOutput::Console);
            }
        }*/
    }

    final public function run(CrawlerModel $crawler): void
    {
        $this->crawler = $crawler;
        $this->crawler->load('platform');

        $url = $crawler->url;
        $isFirstPage = true;
        $nextPageParams = [];

        do {
            $response = $this->fetchPageResponse($url, $isFirstPage, $nextPageParams);
            $pageResult = $this->getPageInfo($response->body());

            foreach ($pageResult->items as $item) {
                $this->processSingleItem($item);
            }

            // Проверяем флаг следующей страницы из DTO
            if (!$pageResult->meta->has_next_page) {
                break;
            }

            $isFirstPage = false;
            // Берем массив параметров из корректного поля DTO
            $nextPageParams = $pageResult->meta->next_page_params;

            usleep(mt_rand(1500000, 2500000));
        } while (true);
    }

    protected function fetchPageResponse(string $url, bool $isFirstPage, array $params): \Illuminate\Http\Client\Response
    {
        $this->logger->info("Загрузка страницы: " . $url, LogOutput::Console);
        $requestUrl = $url;
        if (!$isFirstPage && !empty($params)) {
            $requestUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        $page = $this->browser->openPage($requestUrl);
        return new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(200, [], $page->getHtml())
        );
    }

    protected function processSingleItem(CrawlingItemDto $item): void
    {
        if ($this->isAlreadyIgnored($item->externalId)) return;

        $filterResult = $this->filter($item);
        if ($filterResult !== true) {
            $this->markAsIgnored($item, is_string($filterResult) ? $filterResult : 'Filtered by logic');
            return;
        }

        $this->handle($item);
    }

    abstract protected function getPageInfo(string $html): PageResultDto;
    abstract public function getNodes(string $html): array;
    abstract public function crawlingItem(mixed $node): CrawlingItemDto;

    protected function handle(CrawlingItemDto $item): void
    {
        $linkDto = new LinkDto(
            regNumber: $item->externalId,
            detailUrl: $item->url,
            publishedAt: $item->publishedAt ?? Carbon::now(),
            submissionEndAt: $item->submissionEndAt,
        );
        $this->crawlingRepository()->storeOrUpdateLink($this->getCrawler(), $linkDto);
    }

    final public function getPlatform(): Platform
    {
        return $this->getCrawler()->platform;
    }

    protected function getCrawler(): CrawlerModel
    {
        return $this->crawler ?? throw new RuntimeException('Crawler is not set.');
    }

    protected function crawlingRepository(): CrawlingRepository
    {
        return app(CrawlingRepository::class);
    }

    protected function isAlreadyIgnored(string $externalId): bool
    {
        return IgnoreLinks::query()
            ->where('platform_id', $this->getPlatform()->id)
            ->where('external_id', $externalId)
            ->exists();
    }

    protected function markAsIgnored(CrawlingItemDto $item, string $reason): void
    {
        IgnoreLinks::create([
            'platform_id' => $this->getPlatform()->id,
            'external_id' => $item->externalId,
            'reason' => $reason,
        ]);
    }

    protected function filter(CrawlingItemDto $item): bool|string
    {
        if (mb_stripos($item->title, 'питание') === false) {
            return 'Не содержит ключевое слово: питание';
        }
        return true;
    }
}
