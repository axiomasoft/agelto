<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

/**
 * Запись файла манифеста (§4 `root/service-layer-map.md`) — `estimate:gold:build --update-manifest`,
 * `estimate:fixtures:manifest`. Манифест остаётся SSOT корпуса (D20): сервис только пишет то, что решил
 * вызывающий, форму не навязывает.
 */
final readonly class FixtureManifestWriter
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function write(string $path, array $manifest): void
    {
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }
}
