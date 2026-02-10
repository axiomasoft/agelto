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

    /**
     * Основной метод запуска краулера
     */
    final public function run(CrawlerModel $crawler): void
    {
        $this->crawler = $crawler;
        $this->crawler->load('platform');

        $url = $crawler->url;
        $isFirstPage = true;
        $nextPageParams = [];

        do {
            // 1. Получаем ответ (GET или POST)
            $response = $this->fetchPageResponse($url, $isFirstPage, $nextPageParams);
            dd($response);

            // 2. Парсим страницу (Items + Meta)
            $pageResult = $this->getPageInfo($response->body());

            // 3. Обрабатываем каждый айтем
            foreach ($pageResult->items as $item) {
                $this->processSingleItem($item);
            }

            // 4. Условие выхода
            if (!$pageResult->meta->has_next_page) {
                break;
            }

            $isFirstPage = false;
            $nextPageParams = $pageResult->meta->next_page_params;

            usleep(mt_rand(1500000, 2500000));
        } while (true);
    }

    /**
     * Полная цепочка обработки одного тендера
     */
    protected function processSingleItem(CrawlingItemDto $item): void
    {
        // Проверка на дубликаты/игнор
        if ($this->isAlreadyIgnored($item->externalId)) {
            return;
        }

        // Логика фильтрации (например, по слову "питание")
        $filterResult = $this->filter($item);
        if ($filterResult !== true) {
            $this->markAsIgnored($item, is_string($filterResult) ? $filterResult : 'Filtered by logic');
            return;
        }

        // Сохранение
        $this->handle($item);
    }

    /**
     * Общий метод запроса (с поддержкой JS-пагинации через POST)
     */
    protected function fetchPageResponse(string $url, bool $isFirstPage, array $params): \Illuminate\Http\Client\Response
    {
        $request = Http::withRandomProxy();

        if ($isFirstPage || empty($params)) {
            return $request->get($url)->throw();
        }

        return $request->asForm()->post($url, $params)->throw();
    }

    // --- Абстрактные методы для реализации в EisCrawlingService ---
    abstract protected function getPageInfo(string $html): PageResultDto;
    abstract public function getNodes(string $html): array;
    abstract public function crawlingItem(mixed $node): CrawlingItemDto;

    // --- Методы сохранения и фильтрации (функциональность восстановлена) ---

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
