<?php

declare(strict_types=1);

namespace Platform\Interfaces\Crawling;

use Platform\Dto\Crawling\CrawlingItemDto;
use Platform\Models\Crawling\Crawler;
use Platform\Models\Platform;

interface PlatformCrawling
{
    /**
     * Парсинг одного узла (карточки) в объект DTO
     */
    public function crawlingItem(mixed $node): CrawlingItemDto;

    /**
     * Извлечение всех узлов карточек из HTML
     */
    public function getNodes(string $html): array;

    /**
     * Eloquent-модель платформы для привязки к IgnoreLinks и логам
     */
    public function getPlatform(): Platform;

    /**
     * Запуск краулинга для заданного Crawler (получение HTML и обработка узлов)
     */
    public function run(Crawler $crawler): void;
}
