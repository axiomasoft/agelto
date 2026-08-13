<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Режим краулинга: свежие извещения или полный архив.
 */
enum CrawlerMode: string implements HasLabel
{
    case Fresh = 'fresh';
    case Archive = 'archive';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Fresh => 'Новые извещения',
            self::Archive => 'Полный архив',
        };
    }
}
