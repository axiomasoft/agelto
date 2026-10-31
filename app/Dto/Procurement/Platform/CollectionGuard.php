<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

/** Runtime validation complements PHPDoc types at constructor boundaries. */
final readonly class CollectionGuard
{
    /** @param array<array-key,mixed> $items */
    public static function check(array $items, string $type, int $limit = 1000): void
    {
        if (! array_is_list($items) || count($items) > $limit) {
            throw new \InvalidArgumentException('Invalid bounded collection.');
        }
        foreach ($items as $item) {
            if ($type === 'string' ? ! is_string($item) : ! $item instanceof $type) {
                throw new \InvalidArgumentException('Invalid collection member.');
            }
        }
    }
}
