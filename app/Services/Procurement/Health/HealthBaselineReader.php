<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\HealthBaseline;
use App\Exceptions\Procurement\Health\HealthBaselineUnavailableException;
use JsonException;

final readonly class HealthBaselineReader
{
    public function read(): HealthBaseline
    {
        $configuredPath = (string) config('crawler.health.baseline_path');
        $path = str_starts_with($configuredPath, DIRECTORY_SEPARATOR) ? $configuredPath : base_path($configuredPath);

        if (! is_file($path)) {
            throw new HealthBaselineUnavailableException("файл {$path} не найден");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new HealthBaselineUnavailableException("файл {$path} не читается");
        }

        try {
            $baseline = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HealthBaselineUnavailableException("JSON невалиден ({$exception->getMessage()})");
        }

        if (! is_array($baseline)) {
            throw new HealthBaselineUnavailableException('корень JSON должен быть объектом');
        }

        foreach (['platform', 'captured_at', 'source', 'card_tabs', 'listing_csv', 'thresholds', 'calibrated'] as $key) {
            if (! array_key_exists($key, $baseline)) {
                throw new HealthBaselineUnavailableException("отсутствует обязательный ключ {$key}");
            }
        }

        if (! is_array($baseline['card_tabs']) || ! is_array($baseline['listing_csv']) || ! is_array($baseline['thresholds'])
            || ! is_array($baseline['listing_csv']['required_headers'] ?? null) || ! is_bool($baseline['calibrated'])) {
            throw new HealthBaselineUnavailableException('структура эталона не соответствует контракту');
        }

        return new HealthBaseline(
            platform: (string) $baseline['platform'],
            capturedAt: (string) $baseline['captured_at'],
            source: (string) $baseline['source'],
            cardTabs: $baseline['card_tabs'],
            requiredHeaders: $baseline['listing_csv']['required_headers'],
            thresholds: $baseline['thresholds'],
            calibrated: $baseline['calibrated'],
            sha256: hash_file('sha256', $path),
        );
    }
}
