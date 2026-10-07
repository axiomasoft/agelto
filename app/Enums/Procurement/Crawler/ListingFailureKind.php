<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Класс отказа блока листинга (CSV/HTML). Определяет, ЧЕЙ это сбой — прокси, площадки или
 * нашего парсера, — и как на него реагирует контур управления источником
 * (`SourceCircuitController`): локальный сбой записывается инцидентом и пропускается
 * (skip-and-record), системный — копится брейкером источника и ставит источник на паузу.
 */
enum ListingFailureKind: string
{
    /** Пул прокси пуст/исчерпан — глобальная проблема маршрутизации, источник бессмысленно дёргать. */
    case ProxyPoolExhausted = 'proxy_pool_exhausted';

    /** Прокси отказал (407, обрыв туннеля/соединения через прокси). */
    case ProxyFailure = 'proxy_failure';

    /** Площадка отказывает именно нам: 403/429/451, WAF/капча. */
    case SourceBlocked = 'source_blocked';

    /** Площадка недоступна или сломана: 5xx, 408, таймаут, обрыв соединения без прокси. */
    case SourceUnavailable = 'source_unavailable';

    /** Прочий неуспешный HTTP-статус (404, 410, …) — механизм площадки отдал не то, что ждали. */
    case UnexpectedStatus = 'unexpected_status';

    /** 2xx, но ни одна запись непустого блока не разобрана: смена формата, HTML вместо CSV. */
    case UnrecognizedFormat = 'unrecognized_format';

    /** Блок разобран частично: часть строк не прошла маппинг. Данные блока сохранены. */
    case RowErrors = 'row_errors';

    /**
     * Блок целиком не прочитан и курсор переходит через него (skip-and-record). `RowErrors` —
     * блок прочитан, пропущены только строки.
     */
    public function skipsBlock(): bool
    {
        return $this !== self::RowErrors;
    }

    /** Сбой на стороне прокси, а не площадки или парсера. */
    public function isProxySide(): bool
    {
        return $this === self::ProxyPoolExhausted || $this === self::ProxyFailure;
    }

    /** Сбой нашего разбора (площадка ответила успешно). */
    public function isParserSide(): bool
    {
        return $this === self::UnrecognizedFormat || $this === self::RowErrors;
    }

    /** Открывает брейкер источника сразу, без накопления порога. */
    public function opensCircuitImmediately(): bool
    {
        return $this === self::ProxyPoolExhausted;
    }

    /** Учитывается брейкером источника. Частичные ошибки строк — нет: блок прочитан. */
    public function countsTowardCircuit(): bool
    {
        return $this !== self::RowErrors;
    }

    public function label(): string
    {
        return match ($this) {
            self::ProxyPoolExhausted => 'Пул прокси исчерпан',
            self::ProxyFailure => 'Сбой прокси',
            self::SourceBlocked => 'Площадка блокирует (403/429/капча)',
            self::SourceUnavailable => 'Площадка недоступна (5xx/таймаут)',
            self::UnexpectedStatus => 'Неожиданный HTTP-статус',
            self::UnrecognizedFormat => 'Формат ответа не распознан',
            self::RowErrors => 'Ошибки отдельных строк',
        };
    }
}
