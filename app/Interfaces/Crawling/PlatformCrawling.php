<?php

declare(strict_types=1);

namespace App\Interfaces\Crawling;

use App\Dto\Crawling\CrawlingItemDto;
use App\Models\Crawler\Crawler;
use App\Models\Platform;

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
     * Eloquent-модель платформы для привязки к IgnoredLink и логам
     */
    public function getPlatform(): Platform;

    /**
     * Запуск краулинга для заданного Crawler (получение HTML и обработка узлов)
     */
    public function crawlingPage(string $url): void;
}
