<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

/**
 * HTML-фикстуры вкладок карточки закупки ЕИС 44-ФЗ (`tests/Fixtures/Platforms/Eis/Card/*.html`) —
 * сняты с реальной карточки 0338200009125000001, персональные контакты обезличены.
 */
final class CardHtmlFixture
{
    public static function commonInfo(): string
    {
        return str_replace('</body>', self::read(tab: 'common-info-parties').'</body>', self::read(tab: 'common-info'));
    }

    public static function documents(): string
    {
        return self::read(tab: 'documents');
    }

    public static function eventJournal(): string
    {
        return self::read(tab: 'event-journal');
    }

    public static function supplierResults(): string
    {
        return self::read(tab: 'supplier-results');
    }

    private static function read(string $tab): string
    {
        return file_get_contents(base_path("tests/Fixtures/Platforms/Eis/Card/{$tab}.html"));
    }
}
