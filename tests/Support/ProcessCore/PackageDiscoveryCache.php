<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

final class PackageDiscoveryCache
{
    public static function refresh(string $root): void
    {
        // Bootstrap volume переживает composer.json; stale discovery подключал active provider.
        (new PackageManifest(new Filesystem, $root, $root.'/bootstrap/cache/packages.php'))->build();
    }
}
