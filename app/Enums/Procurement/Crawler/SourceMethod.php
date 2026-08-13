<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Способ забора данных с площадки.
 */
enum SourceMethod: string implements HasLabel
{
    case Csv = 'csv';
    case Html = 'html';
    case Api = 'api';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Csv => 'CSV-файл',
            self::Html => 'HTML-страница',
            self::Api => 'API',
        };
    }
}
