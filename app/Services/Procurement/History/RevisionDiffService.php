<?php

declare(strict_types=1);

namespace App\Services\Procurement\History;

use App\Dto\Procurement\History\RevisionDiff;

/** Сравнивает нормализованные снапшоты канонического слоя и не зависит от площадки. */
final readonly class RevisionDiffService
{
    /**
     * @param  array<string, mixed>  $oldSnapshot
     * @param  array<string, mixed>  $newSnapshot
     */
    public function diff(array $oldSnapshot, array $newSnapshot): RevisionDiff
    {
        $changes = [];
        $keys = array_unique([...array_keys($oldSnapshot), ...array_keys($newSnapshot)]);
        sort($keys);

        foreach ($keys as $key) {
            $oldValue = $oldSnapshot[$key] ?? null;
            $newValue = $newSnapshot[$key] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = ['key' => $key, 'label' => RevisionDiff::labelFor($key), 'old' => $oldValue, 'new' => $newValue];
        }

        return new RevisionDiff(changes: $changes);
    }
}
