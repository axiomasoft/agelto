<?php

declare(strict_types=1);

use App\Services\Procurement\Eis\EisSourceClock;

test('время источника читается в Europe/Moscow по умолчанию и возвращается в UTC', function (): void {
    config()->set('crawler.platforms.eis.source_timezone', 'Europe/Moscow');

    $date = (new EisSourceClock)->parseDateTime(format: '!d.m.Y H:i', value: '01.10.2026 10:15');

    expect($date?->toIso8601String())->toBe('2026-10-01T07:15:00+00:00');
});

test('часовой пояс источника берётся из конфигурации', function (): void {
    config()->set('crawler.platforms.eis.source_timezone', 'Asia/Vladivostok');

    $date = (new EisSourceClock)->parseDateTime(format: '!d.m.Y H:i:s', value: '01.10.2026 03:00:00');

    expect($date?->toIso8601String())->toBe('2026-09-30T17:00:00+00:00');
});

test('календарная дата без времени не сдвигается часовым поясом', function (): void {
    $date = (new EisSourceClock('Europe/Moscow'))->parseDate(format: '!d.m.Y', value: '01.10.2026');

    expect($date?->toIso8601String())->toBe('2026-10-01T00:00:00+00:00');
});

test('метка МСК±N задаёт фиксированное смещение', function (string $label, ?string $expected): void {
    $clock = new EisSourceClock('Europe/Moscow');
    $timezone = $clock->timezoneFromMskLabel(label: $label);

    expect($timezone === null ? null : $clock->parseDateTime(format: '!d.m.Y H:i', value: '30.06.2026 08:22', timezone: $timezone)?->toIso8601String())
        ->toBe($expected);
})->with([
    'МСК' => ['МСК', '2026-06-30T05:22:00+00:00'],
    'МСК+3' => ['МСК+3', '2026-06-30T02:22:00+00:00'],
    'МСК-1' => ['МСК-1', '2026-06-30T06:22:00+00:00'],
    'мусор' => ['UTC', null],
]);

test('некорректное значение даёт null', function (): void {
    expect((new EisSourceClock('Europe/Moscow'))->parseDateTime(format: '!d.m.Y H:i', value: 'вчера'))->toBeNull();
});

test('невозможная календарная дата не нормализуется в другой день', function (string $date): void {
    $clock = new EisSourceClock('Europe/Moscow');

    expect($clock->parseDate(format: '!d.m.Y', value: $date))->toBeNull()
        ->and($clock->parseDateTime(format: '!d.m.Y H:i', value: $date.' 10:00'))->toBeNull();
})->with(['31.02.2026', '29.02.2025', '01.13.2026']);
