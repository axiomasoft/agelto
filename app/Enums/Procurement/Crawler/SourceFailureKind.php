<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Почему источник не дал данных. Классификация детерминирована: только тип исключения,
 * HTTP-статус и проверка формата ответа — без разбора текста ошибок и угадывания по телу.
 *
 * Правило для CSV: файл либо скачался (2xx), либо нет; скачанный обязан соответствовать
 * формату (заголовок с обязательными колонками, разбираемые записи). Для HTML-карточки:
 * базовые селекторы эталона есть на каждой странице; нет — страница не обрабатывается.
 */
enum SourceFailureKind: string implements HasLabel
{
    /** Нет доступного прокси (`NoProxyAvailableException`) или прокси ответил 407. */
    case ProxyUnavailable = 'proxy_unavailable';

    /** Площадка отказывает нам: 401/403/429/451. */
    case SourceBlocked = 'source_blocked';

    /** Не скачалось: 408/5xx или ошибка соединения. */
    case SourceUnavailable = 'source_unavailable';

    /** Прочий не-2xx (404, 410, …). */
    case UnexpectedStatus = 'unexpected_status';

    /** Скачалось, но не по формату: нет обязательных колонок или ни одна запись не разобрана. */
    case InvalidContent = 'invalid_content';

    /** Блок принят, но часть строк не разобрана. Не пропуск и не повод для паузы. */
    case RowErrors = 'row_errors';

    /** HTML-карточка: нет базовых селекторов эталона — вёрстка изменилась. */
    case LayoutDrift = 'layout_drift';

    /** Блок целиком не прочитан (курсор идёт дальше, инцидент записан). */
    public function skipsBlock(): bool
    {
        return $this !== self::RowErrors;
    }

    /** Учитывается брейкером этапа. Ошибки строк — нет: источник ответил по формату. */
    public function countsTowardCircuit(): bool
    {
        return $this !== self::RowErrors;
    }

    public function label(): string
    {
        return match ($this) {
            self::ProxyUnavailable => 'Прокси недоступен',
            self::SourceBlocked => 'Площадка блокирует (401/403/429/451)',
            self::SourceUnavailable => 'Не скачалось (5xx/таймаут/соединение)',
            self::UnexpectedStatus => 'Неожиданный HTTP-статус',
            self::InvalidContent => 'Ответ не по формату',
            self::RowErrors => 'Ошибки отдельных строк',
            self::LayoutDrift => 'Изменилась вёрстка страницы',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
