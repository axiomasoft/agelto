<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Eis;

/**
 * Тело реального win1251-экспорта ЕИС (`tests/Fixtures/Platforms/Eis/Csv/eis-block-win1251.csv`) — 4
 * строки данных, разделитель `;`, эскейпленные кавычки `""`, русский заголовок.
 */
final class EisCsvFixture
{
    public static function body(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/eis-block-win1251.csv'));
    }
}
