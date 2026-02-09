<?php

declare(strict_types=1);

namespace Eis\Crawling;

use Platform\Crawling\AbstractCrawlingService;
use Platform\Models\Crawling\Crawler;
use Platform\Dto\Crawling\LinkDto;

class EisCrawlingService extends AbstractCrawlingService
{
    public function run(Crawler $crawler): void
    {
        // 1. Получаем данные из API ЕИС (используя настройки из $crawler->settings)
        // $items = $this->client->fetch($crawler->filters);

        foreach ($items as $item) {
            // 2. Создаем типизированный объект данных
            $dto = new LinkDto(
                regNumber: $item['regNum'],
                detailUrl: $item['url'],
                publishedAt: now(), // или дата из API
            );

            // 3. Сохраняем через базовый метод
            $this->storeLink($crawler, $dto);
        }
    }
}
