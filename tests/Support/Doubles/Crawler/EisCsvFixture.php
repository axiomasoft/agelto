<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

/**
 * Тело реального win1251-экспорта ЕИС (`tests/Fixtures/Crawler/eis-block-win1251.csv`) — 4
 * строки данных, разделитель `;`, эскейпленные кавычки `""`, русский заголовок.
 */
final class EisCsvFixture
{
    public static function body(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Crawler/eis-block-win1251.csv'));
    }
}
