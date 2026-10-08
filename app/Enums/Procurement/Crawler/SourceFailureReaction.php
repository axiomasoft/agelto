<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Что этап делает с отказом данного вида. Задаётся в `config('crawler.source_control')` для
 * каждой пары (этап, вид отказа); значения по умолчанию и смысл — там же.
 *
 * Пропуск блока/карточки от реакции не зависит: данных нет — обрабатывать нечего.
 * Реакция решает только, что записать и считать ли отказ брейкером.
 */
enum SourceFailureReaction: string implements HasLabel
{
    /** Только строка лога с кодом. Ни журнала, ни счётчика. */
    case LogOnly = 'log_only';

    /** Лог + запись в журнал (`prc_source_incidents`). Брейкер не считает. */
    case Record = 'record';

    /** Лог + журнал + счётчик отказов; порог → ожидание этапа по таймеру. */
    case CountTowardWait = 'count_toward_wait';

    /** Лог + журнал + отдельный счётчик «структура»; порог → остановка до оператора. */
    case CountTowardHold = 'count_toward_hold';

    public function recordsIncident(): bool
    {
        return $this !== self::LogOnly;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LogOnly => 'Только лог',
            self::Record => 'Журнал, без счёта',
            self::CountTowardWait => 'Счёт → ожидание',
            self::CountTowardHold => 'Счёт → остановка до оператора',
        };
    }
}
