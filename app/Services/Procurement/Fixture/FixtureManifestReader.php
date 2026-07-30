<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

use App\Exceptions\Procurement\ManifestUnreadable;
use JsonException;

/**
 * Единственный владелец чтения `estimates/manifest.json` (§4/§5 `root/service-layer-map.md`):
 * `estimate:fixtures:import`, `estimate:fixtures:verify`, `estimate:gold:build` читали манифест побайтово одинаково
 * (findings §3) — гвард формы вынесен сюда, а не скопирован трижды.
 */
final readonly class FixtureManifestReader
{
    /**
     * @return array{version: int, fixtures: list<mixed>}
     *
     * @throws ManifestUnreadable
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new ManifestUnreadable("Манифест не найден: {$path}");
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestUnreadable("Манифест не распарсен: {$e->getMessage()}");
        }

        if (! is_array($decoded) || ! is_array($decoded['fixtures'] ?? null)) {
            throw new ManifestUnreadable('Ожидается объект вида {"version": 1, "fixtures": [...]}');
        }

        /** @var array{version: int, fixtures: list<mixed>} $decoded */
        return $decoded;
    }
}
