<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Этап сбора со своим независимым брейкером. Листинг (CSV) и карточки (HTML) ходят в разные
 * части площадки и ломаются по-разному, поэтому падение одного не останавливает другой.
 */
enum SourceStage: string implements HasLabel
{
    case Listing = 'listing';
    case Detail = 'detail';

    public function getLabel(): string
    {
        return match ($this) {
            self::Listing => 'Листинг (CSV)',
            self::Detail => 'Карточки (HTML)',
        };
    }
}
