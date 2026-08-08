<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Конечный lease одной job профиля: значение описывает только класс исполнения, а не секунды
 * инфраструктуры. Значения timeout/retry_after принадлежат route-map и конфигурации (D117).
 */
enum CrawlerExecutionLane: string
{
    case Standard = 'standard';
    case Long = 'long';
}
