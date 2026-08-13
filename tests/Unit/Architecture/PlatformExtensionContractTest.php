<?php

declare(strict_types=1);

function platformExtensionSourceLiteralViolations(array $paths): array
{
    return array_values(array_filter($paths, static function (string $path): bool {
        if (preg_match('/\\b(?:Eis|eis|fz44|fz223)\\b/', (string) file_get_contents($path)) !== 1) {
            return false;
        }

        return ! platformExtensionSourceLiteralIsAllowed(path: $path);
    }));
}

function platformExtensionSourceLiteralIsAllowed(string $path): bool
{
    $allowedRoots = [
        app_path('Services/Procurement/Eis'),
        database_path('seeders/Procurement'),
    ];
    $allowedPaths = [
        app_path('Console/Commands/Procurement/HealthCheckCommand.php'),
        app_path('Console/Commands/Procurement/SyncOkpd2Nodes.php'),
        app_path('Dto/Procurement/Crawler/EisSearchFilter.php'),
        app_path('Dto/Procurement/Crawler/Contracts/RequiresScope.php'),
        app_path('Enums/Procurement/EisProcedureType.php'),
        app_path('Enums/Procurement/ProcurementStatus.php'),
        app_path('Filament/Resources/Procurement/Crawler/Crawlers/Schemas/EisCrawlerPlatformForm.php'),
        app_path('Models/Procurement/Platform/Registry.php'),
        app_path('Providers/AppServiceProvider.php'),
        app_path('Services/Health/ReleaseStateReadiness.php'),
        app_path('Services/Procurement/ArchiveWindowScanner.php'),
        app_path('Services/Procurement/Contracts/ListingRowMapper.php'),
        app_path('Services/Procurement/Contracts/SourceMethod.php'),
        app_path('Services/Procurement/Detail/CommonInfoProcessor.php'),
        app_path('Services/Procurement/Exceptions/SourceMethodUnavailableException.php'),
        app_path('Services/Procurement/Health/Checks/ListingFilterCheck.php'),
        app_path('Services/Procurement/Modes/DetailAnalysisProcessor.php'),
        app_path('Services/Procurement/Modes/FreshRunProcessor.php'),
        config_path('crawler.php'),
        database_path('seeders/StatusMappingSeeder.php'),
    ];

    return in_array($path, $allowedPaths, strict: true)
        || array_any($allowedRoots, static fn (string $root): bool => str_starts_with($path, $root.DIRECTORY_SEPARATOR));
}

function platformExtensionPhpSources(): array
{
    $paths = [config_path('crawler.php')];

    foreach ([app_path(), database_path('seeders')] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }

    return $paths;
}

test('source-specific literals разрешены только в exact adapter, descriptor, config и seed boundary', function (): void {
    expect(platformExtensionSourceLiteralViolations(paths: platformExtensionPhpSources()))->toBe([]);
});

test('forbidden-core fixture не проходит exact source-literal allowlist', function (): void {
    $fixture = tempnam(sys_get_temp_dir(), 'platform-extension-forbidden-');

    expect($fixture)->not->toBeFalse();

    try {
        file_put_contents($fixture, '<?php if ($platform === "eis") { return "fz44"; }');

        expect(platformExtensionSourceLiteralViolations(paths: [$fixture]))->toBe([$fixture]);
    } finally {
        @unlink($fixture);
    }
});
