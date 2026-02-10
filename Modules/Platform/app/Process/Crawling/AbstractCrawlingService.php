<?php

declare(strict_types=1);

namespace Platform\Process\Crawling;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
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

    final public function run(CrawlerModel $crawler): void
    {
        $this->crawler = $crawler;
        $this->crawler->load('platform');

        $url = $crawler->url;
        $isFirstPage = true;
        $nextPageParams = [];

        do {
            try {
                // Вызов общего или переопределенного метода запроса
                $response = $this->fetchPageResponse($url, $isFirstPage, $nextPageParams);
                $pageResult = $this->parseHtmlToDto($response->body());

                foreach ($pageResult->items as $item) {
                    $this->processSingleItem($item);
                }

                if (!$pageResult->meta->has_next_page) break;

                $isFirstPage = false;
                $nextPageParams = $pageResult->meta->next_page_params;

                usleep(mt_rand(1000000, 2500000));
            } catch (Exception $e) {
                Log::error("Crawling Error [{$this->getPlatform()->code}]: " . $e->getMessage());
                break;
            }
        } while (true);
    }

    /**
     * Общий метод запроса: если есть параметры для следующего шага — шлем POST форму, иначе GET.
     */
    protected function fetchPageResponse(string $url, bool $isFirstPage, array $params): \Illuminate\Http\Client\Response
    {
        $request = Http::withRandomProxy();

        if ($isFirstPage || empty($params)) {
            return $request->get($url)->throw();
        }

        return $request->asForm()->post($url, $params)->throw();
    }

    abstract protected function parseHtmlToDto(string $html): PageResultDto;
    abstract public function getNodes(string $html): array;
    abstract public function crawlingItem(mixed $node): CrawlingItemDto;

    // --- Вспомогательные методы (handle, filter, etc.) остаются прежними ---
    protected function processSingleItem(CrawlingItemDto $item): void
    {
        if ($this->isAlreadyIgnored($item->externalId)) return;

        $filterResult = $this->filter($item);
        if ($filterResult !== true) {
            $this->markAsIgnored($item, is_string($filterResult) ? $filterResult : 'Filtered');
            return;
        }

        $this->handle($item);
    }

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

    public function getPlatform(): Platform
    {
        return $this->getCrawler()->platform;
    }
    protected function getCrawler(): CrawlerModel
    {
        return $this->crawler ?? throw new RuntimeException('Crawler not set.');
    }
    protected function crawlingRepository(): CrawlingRepository
    {
        return app(CrawlingRepository::class);
    }
    protected function isAlreadyIgnored(string $externalId): bool
    {
        return IgnoreLinks::query()->where('platform_id', $this->getPlatform()->id)->where('external_id', $externalId)->exists();
    }
    protected function markAsIgnored(CrawlingItemDto $item, string $reason): void
    {
        IgnoreLinks::create(['platform_id' => $this->getPlatform()->id, 'external_id' => $item->externalId, 'reason' => $reason]);
    }
    protected function filter(CrawlingItemDto $item): bool|string
    {
        return mb_stripos($item->title, 'питание') !== false ? true : 'Не содержит "питание"';
    }
}
