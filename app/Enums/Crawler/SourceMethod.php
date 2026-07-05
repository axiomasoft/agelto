<?php

declare(strict_types=1);

namespace App\Enums\Crawler;

/**
 * Способ забора данных с площадки.
 */
enum SourceMethod: string
{
    case Csv = 'csv';
    case Html = 'html';
    case Api = 'api';
}
