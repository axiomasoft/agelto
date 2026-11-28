<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Общая формула SHA-шардинга пути блоба без доменных зависимостей.
 */
final class ShardedBlobPath
{
    public static function for(string $hash): string
    {
        return 'documents/'.substr($hash, 0, 2)."/{$hash}";
    }
}
