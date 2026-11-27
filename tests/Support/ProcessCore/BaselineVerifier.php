<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

final class BaselineVerifier
{
    /**
     * @param  list<array{path: string, sha256: string}>  $entries
     * @return list<string>
     */
    public static function violations(string $root, array $entries): array
    {
        $violations = [];

        foreach ($entries as $entry) {
            $path = $root.'/'.$entry['path'];

            if (! is_file($path) || hash_file('sha256', $path) !== $entry['sha256']) {
                $violations[] = $entry['path'];
            }
        }

        return $violations;
    }
}
