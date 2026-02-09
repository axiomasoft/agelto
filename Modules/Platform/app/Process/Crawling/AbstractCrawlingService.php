<?php

declare(strict_types=1);

namespace Platform\Crawling;

use Illuminate\Support\Facades\Log;
use Platform\Dto\Crawling\LinkDto;
use Platform\Models\Crawling\Crawler;
use Platform\Models\Crawling\Link;
use Platform\Repositories\Crawling\CrawlingRepository;

abstract class AbstractCrawlingService
{
    public function __construct(
        protected readonly CrawlingRepository $crawlingRepository
    ) {}

    /**
     * Метод, который реализует конкретная площадка (Eis, B2B и т.д.)
     */
    abstract public function run(Crawler $crawler): void;

    /**
     * Сохранение или обновление найденной ссылки
     */
    protected function storeLink(Crawler $crawler, LinkDto $dto): Link
    {
        $link = $this->crawlingRepository->storeOrUpdateLink($crawler, $dto);

        Log::info("Crawling: Link [{$dto->regNumber}] stored for crawler #{$crawler->id}");

        return $link;
    }
}
