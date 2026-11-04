<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Fixture;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Контракт `estimates/manifest.json` — SSOT корпуса эталонов в git.
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
