<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/** Результат учёта отказа брейкером этапа. */
enum SourceBreakerOutcome
{
    /** Этап работает дальше. */
    case Running;

    /** Этот отказ увёл этап в ожидание по таймеру. */
    case StartedWaiting;

    /** Этот отказ остановил этап до решения оператора (изменилась структура). */
    case StartedHold;

    /** Этап уже не принимал запросы (ждёт или стоит). */
    case NotAdmitting;

    public function admits(): bool
    {
        return $this === self::Running;
    }
}
