<?php

declare(strict_types=1);

namespace App\Services\Procurement\Fixture;

/**
 * Записывает estimates/manifest.json для estimate:gold:build --update-manifest
 * и estimate:fixtures:manifest. Манифест остаётся источником корпуса; содержимое
 * определяет caller, writer только сохраняет файл.
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
