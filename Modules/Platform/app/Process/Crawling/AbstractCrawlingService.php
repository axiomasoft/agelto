<?php

declare(strict_types=1);

namespace Platform\Process\Crawling;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Dto\Crawling\LinkDto;
use Platform\Interfaces\Crawling\PlatformCrawling;
use Platform\Models\Crawling\Crawler;
use Platform\Models\Crawling\IgnoreLinks;
use Platform\Models\Platform;
use Platform\Repositories\Crawling\CrawlingRepository;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class AbstractCrawlingService implements PlatformCrawling
{
    protected ?Crawler $crawler = null;

    /**
     * Основной метод запуска краулера
     */
    final public function run(Crawler $crawler): void
    {
        $crawler->load('platform');
        $this->crawler = $crawler;
        $html = $this->fetchHtml($crawler->url);
        $platform = $this->getPlatform();

        foreach ($this->getNodes($html) as $node) {
            try {
                $item = $this->crawlingItem($node);

                if ($this->isAlreadyIgnored($item->externalId)) {
                    continue;
                }

                $filterResult = $this->filter($item);
                if ($filterResult !== true) {
                    $reason = is_string($filterResult) ? $filterResult : 'Filtered by logic';
                    $this->markAsIgnored($item, $reason);

                    continue;
                }

                $this->handle($item);
            } catch (Exception $e) {
                Log::error("Crawling Error [{$platform->code}]: " . $e->getMessage());
            }
        }
    }

    final public function getPlatform(): Platform
    {
        return $this->getCrawler()->platform;
    }

    protected function getCrawler(): Crawler
    {
        if ($this->crawler === null) {
            throw new RuntimeException('Crawler is not set. Call run(Crawler) first.');
        }

        return $this->crawler;
    }

    /**
     * Общее сохранение валидной закупки в plt_crawler_links через репозиторий
     */
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


    protected function fetchHtml(string $url): string
    {
        return Http::withRandomProxy()
            ->withOptions(['debug' => true])
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * Переопределяем фильтр под конкретные нужды (например, поиск по ключевым словам)
     */
    protected function filter(CrawlingItemDto $item): bool|string
    {
        if (mb_stripos($item->title, 'питание') === false) {
            return 'Не содержит ключевое слово: питание';
        }

        return true;
    }
}
