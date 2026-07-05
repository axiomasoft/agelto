<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

/**
 * HTML-фикстуры вкладок карточки закупки ЕИС 44-ФЗ (`tests/Fixtures/Crawler/Card/*.html`) —
 * структурно представляют разделы, описанные в `research/eis-card-recon.md` §3–§5
 * (сырого HTML-дампа карточки в разведке нет, только текстовое описание полей).
 */
final class CardHtmlFixture
{
    public static function commonInfo(): string
    {
        return self::read(tab: 'common-info');
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
        return file_get_contents(base_path("tests/Fixtures/Crawler/Card/{$tab}.html"));
    }
}
