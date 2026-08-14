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
        $configuredPath = config('crawler.health.baseline_path');

        if (! is_string($configuredPath) || $configuredPath === '') {
            throw new HealthBaselineUnavailableException('путь к эталону должен быть непустой строкой');
        }

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

        $platform = $baseline['platform'];
        $capturedAt = $baseline['captured_at'];
        $source = $baseline['source'];
        $cardTabs = $baseline['card_tabs'];
        $listingCsv = $baseline['listing_csv'];
        $thresholds = $baseline['thresholds'];
        $calibrated = $baseline['calibrated'];

        if (! is_string($platform) || ! is_string($capturedAt) || ! is_string($source)
            || ! is_array($cardTabs) || ! is_array($listingCsv) || ! is_array($thresholds)
            || ! is_array($listingCsv['required_headers'] ?? null) || ! is_bool($calibrated)) {
            throw new HealthBaselineUnavailableException('структура эталона не соответствует контракту');
        }

        $sha256 = hash_file('sha256', $path);

        if (! is_string($sha256)) {
            throw new HealthBaselineUnavailableException("не удалось вычислить SHA-256 для {$path}");
        }

        return new HealthBaseline(
            platform: $platform,
            capturedAt: $capturedAt,
            source: $source,
            cardTabs: self::mapOfMaps(value: $cardTabs),
            requiredHeaders: self::stringList(value: $listingCsv['required_headers']),
            thresholds: self::mapOfMaps(value: $thresholds),
            calibrated: $calibrated,
            sha256: $sha256,
        );
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, array<string, mixed>>
     */
    private static function mapOfMaps(array $value): array
    {
        $result = [];

        foreach ($value as $group => $entries) {
            if (! is_string($group) || ! is_array($entries)) {
                throw new HealthBaselineUnavailableException('структура эталона не соответствует контракту');
            }

            $normalizedEntries = [];

            foreach ($entries as $key => $entry) {
                if (! is_string($key)) {
                    throw new HealthBaselineUnavailableException('структура эталона не соответствует контракту');
                }

                $normalizedEntries[$key] = $entry;
            }

            $result[$group] = $normalizedEntries;
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function stringList(array $value): array
    {
        $result = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new HealthBaselineUnavailableException('структура эталона не соответствует контракту');
            }

            $result[] = $entry;
        }

        return $result;
    }
}
