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

    /** 2xx с пустым телом — по наблюдениям ЕИС так не отвечает. Пропуск + критичный инцидент. */
    case EmptyBody = 'empty_body';

    /** Скачалось, записи есть, но ни одна не разобрана. */
    case InvalidContent = 'invalid_content';

    /** Блок принят, но часть строк не разобрана. Не пропуск и не повод для паузы. */
    case RowErrors = 'row_errors';

    /** Исключение нашего кода (не сеть, не площадка). Не учитывается брейкером. */
    case CodeError = 'code_error';

    /**
     * Изменилась структура источника: в заголовке CSV нет обязательных колонок или на карточке
     * нет базовых селекторов. Сам не починится — серия таких отказов останавливает этап до
     * решения оператора (`requiresOperator`), а не ставит таймер.
     */
    case LayoutDrift = 'layout_drift';

    /** Блок целиком не прочитан (курсор идёт дальше, инцидент записан). */
    public function skipsBlock(): bool
    {
        return $this !== self::RowErrors;
    }

    /**
     * Учитывается брейкером этапа. Не учитываются: ошибки строк (блок принят), пустое тело (по
     * решению владельца обход не останавливается, только громко сообщает) и ошибки нашего кода
     * (площадка тут ни при чём — останавливать сбор из-за них нельзя).
     */
    public function countsTowardCircuit(): bool
    {
        return ! in_array($this, [self::RowErrors, self::EmptyBody, self::CodeError], true);
    }

    /** Серия таких отказов останавливает этап до оператора, автоповтора нет. */
    public function requiresOperator(): bool
    {
        return $this === self::LayoutDrift;
    }

    public function label(): string
    {
        return match ($this) {
            self::ProxyUnavailable => 'Прокси недоступен',
            self::SourceBlocked => 'Площадка блокирует (401/403/429/451)',
            self::SourceUnavailable => 'Не скачалось (5xx/таймаут/соединение)',
            self::UnexpectedStatus => 'Неожиданный HTTP-статус',
            self::EmptyBody => 'Пустой ответ',
            self::CodeError => 'Ошибка кода',
            self::InvalidContent => 'Записи не разобраны',
            self::RowErrors => 'Ошибки отдельных строк',
            self::LayoutDrift => 'Изменилась структура (колонки/селекторы)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
