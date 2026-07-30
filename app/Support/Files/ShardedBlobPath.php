<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Технический слой, доменов не знает (скилл `laravel-structure`, ось Technical axis) — общая
 * формула sha-шардинга пути блоба (§5 `root/service-layer-map.md`).
 */
final class ShardedBlobPath
{
    public static function for(string $hash): string
    {
        return 'documents/'.substr($hash, 0, 2)."/{$hash}";
    }
}
