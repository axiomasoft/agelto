<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Fixture;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Контракт `estimates/manifest.json` — SSOT корпуса эталонов в git (D1/D20).
 */
final class FixtureManifest extends Data
{
    /**
     * @param  DataCollection<int, FixtureManifestEntry>  $fixtures
     */
    public function __construct(
        public int $version,
        public DataCollection $fixtures,
    ) {}
}
