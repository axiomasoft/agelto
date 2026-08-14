<?php

declare(strict_types=1);

use App\Exceptions\Procurement\Health\HealthBaselineUnavailableException;
use App\Services\Procurement\Health\HealthBaselineReader;

test('ридер возвращает типизированный эталон и хеширует байты файла', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'health-baseline-');
    config(['crawler.health.baseline_path' => $path]);

    file_put_contents($path, json_encode([
        'platform' => 'eis', 'captured_at' => '2026-08-08T00:00:00+00:00', 'source' => 'test',
        'card_tabs' => [], 'listing_csv' => ['required_headers' => []], 'thresholds' => [], 'calibrated' => false,
    ], JSON_THROW_ON_ERROR));

    $first = app(HealthBaselineReader::class)->read();
    file_put_contents($path, file_get_contents($path).' ');
    $second = app(HealthBaselineReader::class)->read();

    expect($first->platform)->toBe('eis')
        ->and($first->calibrated)->toBeFalse()
        ->and($second->sha256)->not->toBe($first->sha256);

    unlink($path);
});

test('ридер отклоняет эталон без обязательного ключа', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'health-baseline-');
    config(['crawler.health.baseline_path' => $path]);
    file_put_contents($path, json_encode(['platform' => 'eis'], JSON_THROW_ON_ERROR));

    expect(fn () => app(HealthBaselineReader::class)->read())
        ->toThrow(HealthBaselineUnavailableException::class, 'отсутствует обязательный ключ');

    unlink($path);
});

test('ридер отклоняет нетипизированные значения эталона', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'health-baseline-');
    config(['crawler.health.baseline_path' => $path]);
    file_put_contents($path, json_encode([
        'platform' => 44, 'captured_at' => '2026-08-08T00:00:00+00:00', 'source' => 'test',
        'card_tabs' => [], 'listing_csv' => ['required_headers' => []], 'thresholds' => [], 'calibrated' => false,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => app(HealthBaselineReader::class)->read())
        ->toThrow(HealthBaselineUnavailableException::class, 'структура эталона не соответствует контракту');

    unlink($path);
});
