<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckVerdict;

test('вердикт правильно определяет ухудшение на всех парах', function (
    PlatformCheckVerdict $current,
    PlatformCheckVerdict $previous,
    bool $expected,
): void {
    expect($current->isWorseThan($previous))->toBe($expected);
})->with([
    'ok after ok' => [PlatformCheckVerdict::Ok, PlatformCheckVerdict::Ok, false],
    'ok after not enough data' => [PlatformCheckVerdict::Ok, PlatformCheckVerdict::NotEnoughData, false],
    'ok after degraded' => [PlatformCheckVerdict::Ok, PlatformCheckVerdict::Degraded, false],
    'ok after broken' => [PlatformCheckVerdict::Ok, PlatformCheckVerdict::Broken, false],
    'not enough data after ok' => [PlatformCheckVerdict::NotEnoughData, PlatformCheckVerdict::Ok, true],
    'not enough data after itself' => [PlatformCheckVerdict::NotEnoughData, PlatformCheckVerdict::NotEnoughData, false],
    'not enough data after degraded' => [PlatformCheckVerdict::NotEnoughData, PlatformCheckVerdict::Degraded, false],
    'not enough data after broken' => [PlatformCheckVerdict::NotEnoughData, PlatformCheckVerdict::Broken, false],
    'degraded after ok' => [PlatformCheckVerdict::Degraded, PlatformCheckVerdict::Ok, true],
    'degraded after not enough data' => [PlatformCheckVerdict::Degraded, PlatformCheckVerdict::NotEnoughData, true],
    'degraded after itself' => [PlatformCheckVerdict::Degraded, PlatformCheckVerdict::Degraded, false],
    'degraded after broken' => [PlatformCheckVerdict::Degraded, PlatformCheckVerdict::Broken, false],
    'broken after ok' => [PlatformCheckVerdict::Broken, PlatformCheckVerdict::Ok, true],
    'broken after not enough data' => [PlatformCheckVerdict::Broken, PlatformCheckVerdict::NotEnoughData, true],
    'broken after degraded' => [PlatformCheckVerdict::Broken, PlatformCheckVerdict::Degraded, true],
    'broken after itself' => [PlatformCheckVerdict::Broken, PlatformCheckVerdict::Broken, false],
]);
