<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Режим краулинга: свежие извещения или полный архив.
 */
enum CrawlerMode: string
{
    case Fresh = 'fresh';
    case Archive = 'archive';
}
