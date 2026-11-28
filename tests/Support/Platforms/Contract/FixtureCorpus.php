<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

/** JSON is decoded only at the versioned fixture boundary; failures never print response bodies. */
final readonly class FixtureCorpus
{
    public static function read(string $path): string
    {
        $manifest = json_decode((string) file_get_contents(base_path('tests/Fixtures/ProcessCore/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['contract_fixtures'] as $entry) {
            if ($entry['path'] !== $path) {
                continue;
            }
            $absolute = realpath(base_path($path));
            if ($absolute === false || ! str_starts_with($absolute, base_path('tests/Fixtures').'/') || is_link(base_path($path))) {
                throw new \LogicException('Missing or unsafe contract fixture: '.$path);
            }
            $body = file_get_contents($absolute);
            if ($body === false || ! hash_equals($entry['sha256'], hash('sha256', $body))) {
                throw new \LogicException('Contract fixture hash mismatch: '.$path);
            }

            return $body;
        }

        throw new \LogicException('Unregistered contract fixture: '.$path);
    }
}
