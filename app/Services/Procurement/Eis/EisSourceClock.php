<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use Carbon\CarbonTimeZone;
use Illuminate\Support\Carbon;

/**
 * Единая точка разбора дат источника ЕИС.
 *
 * ЕИС отдаёт «наивные» локальные дату-время без смещения. Они читаются в часовом поясе
 * источника (`crawler.platforms.eis.source_timezone`, env `EIS_SOURCE_TIMEZONE`,
 * по умолчанию `Europe/Moscow`) и возвращаются в UTC — в UTC же хранятся в БД.
 *
 * Значения без времени (`d.m.Y`) — календарные даты, а не моменты: они возвращаются
 * как полночь UTC того же дня, без сдвига, иначе «01.10» превратилось бы в «30.09 21:00».
 */
final readonly class EisSourceClock
{
    private CarbonTimeZone $sourceTimezone;

    public function __construct(?string $timezone = null)
    {
        $configured = $timezone ?? config('crawler.platforms.eis.source_timezone', 'Europe/Moscow');

        $this->sourceTimezone = new CarbonTimeZone(is_string($configured) && $configured !== '' ? $configured : 'Europe/Moscow');
    }

    public function endOfSourceDay(Carbon $start): Carbon
    {
        return $start->copy()->setTimezone($this->sourceTimezone)->startOfDay()->addDay()->utc();
    }

    public function sourceTimezone(): CarbonTimeZone
    {
        return $this->sourceTimezone;
    }

    /**
     * Локальное время источника → момент в UTC. `null`, если значение не соответствует формату.
     */
    public function parseDateTime(string $format, string $value, ?CarbonTimeZone $timezone = null): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, trim($value), $timezone ?? $this->sourceTimezone);
        } catch (\Throwable) {
            return null;
        }

        if (Carbon::getLastErrors() !== false) {
            return null;
        }

        return $date === null ? null : $date->utc();
    }

    /**
     * Календарная дата источника → полночь UTC того же календарного дня.
     */
    public function parseDate(string $format, string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, trim($value), 'UTC');
        } catch (\Throwable) {
            return null;
        }

        if (Carbon::getLastErrors() !== false) {
            return null;
        }

        return $date?->startOfDay();
    }

    /**
     * Метка ЕИС вида «МСК+3» / «МСК-1» / «МСК» → фиксированное смещение от UTC
     * (МСК = UTC+3, без перехода на летнее время с 2014 г.). `null` — метка не распознана.
     */
    public function timezoneFromMskLabel(string $label): ?CarbonTimeZone
    {
        if (preg_match('/^\s*МСК\s*(?:([+-])\s*(\d{1,2}))?\s*$/u', $label, $m) !== 1) {
            return null;
        }

        $offset = 3 + (isset($m[1], $m[2]) && $m[1] !== '' ? ($m[1] === '-' ? -1 : 1) * (int) $m[2] : 0);

        $timezone = CarbonTimeZone::createFromHourOffset($offset);

        return $timezone === false ? null : $timezone;
    }
}
