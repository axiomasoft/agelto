<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

/** Source rules cover imports, absolute names and literal class strings, including escaped strings. */
final class PlatformSourceRules
{
    /** @return array{namespace: string, dependencies: list<string>, group_use: bool, writes: bool} */
    public static function inspect(string $source): array
    {
        $namespace = '';
        $dependencies = [];
        $groupUse = false;
        $depth = 0;
        $tokens = token_get_all($source);

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

                continue;
            }

            if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], strict: true)) {
                $depth++;
            }

            if ($token[0] === T_NAMESPACE || ($token[0] === T_USE && $depth === 0)) {
                $statement = '';

                while (++$index < $count && $tokens[$index] !== ';') {
                    $statement .= is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];
                }

                if ($token[0] === T_NAMESPACE) {
                    $namespace = trim($statement);
                } elseif (str_contains($statement, '{')) {
                    $groupUse = true;
                } else {
                    foreach (explode(',', $statement) as $import) {
                        $name = preg_replace('/\s+as\s+\w+$/i', '', trim($import));
                        $name = preg_replace('/^(?:function|const)\s+/', '', (string) $name);
                        $dependencies[] = ltrim((string) $name, '\\');
                    }
                }

                continue;
            }

            if (in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], strict: true)) {
                $name = ltrim($token[1], '\\');

                if (preg_match('/^(?:App|Database|Tests)\\\\/', $name) === 1) {
                    $dependencies[] = $name;
                }
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                // PHP preserves unknown escapes; stripcslashes would incorrectly erase \Services.
                $literal = str_replace(['\\\\', '\\'.$token[1][0]], ['\\', $token[1][0]], substr($token[1], 1, -1));
                $literal = ltrim($literal, '\\');
                preg_match_all('/(?:^|[^A-Za-z0-9_\\\\])((?:App|Database|Tests)\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)/', $literal, $matches);
                $dependencies = [...$dependencies, ...$matches[1]];
            }
        }

        $dependencies = array_values(array_unique($dependencies));
        sort($dependencies, SORT_STRING);

        return [
            'namespace' => $namespace,
            'dependencies' => $dependencies,
            'group_use' => $groupUse,
            'writes' => preg_match('/(?:->|::)\s*(?:save|saveOrFail|create|insert|upsert|update|updateOrCreate|firstOrCreate|delete|destroy|truncate|increment|decrement)\s*\(/', $source) === 1,
        ];
    }

    /** @return array<string, list<string>> Permanent integration roles, separate from the shrinking baseline. */
    public static function rolePermissions(): array
    {
        return [
            // Explicit composition roles; no fetch/parser permission is widened.
            'app/Providers/AppServiceProvider.php' => [
                'App\\Dto\\Platforms\\Eis\\EisCardRoutes',
                'App\\Dto\\Platforms\\Eis\\EisPolicy',
                'App\\Services\\Platforms\\Eis\\Card\\EisCardCatalog',
                'App\\Services\\Platforms\\Eis\\EisSourceClock',
            ],
            'app/Console/Platforms/Eis/SyncOkpd2Nodes.php' => [
                'App\\Models\\Procurement\\Crawler\\Crawler',
            ],
            'app/Console/Platforms/Eis/VerifyDocumentUid.php' => [
                'App\\Models\\Procurement\\Platform\\Registry',
                'App\\Services\\Procurement\\Network\\Contracts\\LegacyOutbound',
                'App\\Services\\Procurement\\Proxy\\ProxyResolver',
            ],
            'database/factories/Platforms/Eis/EisCrawlerFactory.php' => [
                'App\\Models\\Procurement\\Crawler\\Crawler',
                'App\\Models\\Procurement\\Crawler\\Proxy',
                'App\\Models\\Procurement\\Platform\\Platform',
                'App\\Models\\Procurement\\Platform\\Registry',
                'App\\Repositories\\Procurement\\Network\\RouteRepository',
                'App\\Services\\Procurement\\Network\\OutboundPolicy',
                'App\\Services\\Procurement\\Process\\ProcessTransaction',
                'Illuminate\\Support\\Facades\\DB',
            ],
            'database/seeders/Platforms/Eis/EisCrawlerProfileSeeder.php' => [
                'App\\Models\\Procurement\\Crawler\\Crawler',
                'App\\Models\\Procurement\\Platform\\Platform',
                'App\\Models\\Procurement\\Platform\\Registry',
            ],
            // Q2: profile preparation is an application integration role, outside fetch/parser purity.
            'app/Services/Platforms/Eis/Listing/EisProfilePreparer.php' => [
                'App\\Models\\Procurement\\Crawler\\Crawler',
                'App\\Repositories\\Procurement\\Crawler\\ListingCoverageRepository',
                'App\\Repositories\\Procurement\\Platform\\Okpd2NodeReadRepository',
                'App\\Repositories\\Procurement\\Process\\LedgerRow',
                'App\\Services\\Procurement\\Acquisition\\Contracts\\AcquisitionProfilePreparer',
                'App\\Services\\Procurement\\Network\\OutboundPolicy',
            ],
            'app/Services/Platforms/Eis/EisDefinition.php' => [
                'App\\Filament\\Platforms\\Eis\\EisUiExtension',
            ],
            'app/Services/Platforms/Eis/EisExtensionPersister.php' => [
                'App\\Repositories\\Procurement\\Process\\CallerTransaction',
            ],
            'app/Filament/Platforms/Eis/EisUiExtension.php' => [
                'App\\Models\\Procurement\\Platform\\Registry',
                'App\\Contracts\\Procurement\\Platform\\PlatformUiExtension',
                'App\\Models\\Procurement\\Procurement',
            ],
            'tests/Support/Platforms/Contract/ContractRegistry.php' => [
                'Tests\\Support\\Platforms\\Eis\\EisContractManifest',
                'Tests\\Support\\Platforms\\JsonProcurement\\JsonContractManifest',
            ],
            'config/platforms.php' => [
                'App\\Services\\Platforms\\Eis\\EisDefinition',
            ],
            'app/Services/Platforms/Eis/Diagnostics/ListingFilterCheck.php' => [
                'App\\Services\\Procurement\\Network\\Contracts\\DiagnosticCheck',
            ],
            'app/Services/Platforms/Eis/Csv/EisCsvParser.php' => [
                'App\\Services\\Procurement\\Network\\BoundedResponseReader',
            ],
            'app/Models/Platforms/Eis/EisDetails.php' => [
                'App\Models\Procurement\Procurement',
            ],
            'app/Filament/Platforms/Eis/EisCrawlerPlatformForm.php' => [
                'App\Contracts\Procurement\Crawler\CrawlerPlatformForm',
                'App\Models\Procurement\Crawler\Crawler',
                'App\Models\Procurement\Platform\Registry',
                'App\Models\Procurement\Platform\Okpd2Node',
                'App\Models\Procurement\Procurement',
                'App\Repositories\Procurement\Platform\Okpd2NodeReadRepository',
            ],
            'app/Services/Platforms/Eis/Filters/Okpd2NodeSynchronizer.php' => [
                'App\\Services\\Procurement\\Network\\Contracts\\LegacyOutbound',
                'App\\Services\\Procurement\\Proxy\\ProxyResolver',
                'App\\Services\\Procurement\\Proxy\\RoutePolicy',
                'App\Models\Procurement\Platform\Platform',
                'App\Models\Procurement\Platform\Okpd2Node',
                'App\Repositories\Procurement\Platform\Okpd2NodeReadRepository',
                'App\Repositories\Procurement\Platform\Okpd2NodeStoreRepository',
            ],
            'database/factories/Platforms/Eis/EisDetailsFactory.php' => [
                'App\Models\Procurement\Procurement',
            ],
            'database/seeders/Platforms/Eis/EisPlatformSeeder.php' => [
                'App\Models\Procurement\Platform\Platform',
                'App\Models\Procurement\Platform\Registry',
            ],
            'database/seeders/Platforms/Eis/EisCrawlerProxySeeder.php' => [
                'App\\Services\\Procurement\\Process\\ProcessTransaction',
                'App\\Repositories\\Procurement\\Process\\ControlRepository',
                'App\\Repositories\\Procurement\\Network\\RouteRepository',
                'App\Models\Procurement\Crawler\Proxy',
            ],
            'database/seeders/Platforms/Eis/EisJanuary2025AuditSeeder.php' => [
                'App\\Repositories\\Procurement\\Network\\RouteRepository',
                'App\Models\Procurement\Crawler\Crawler',
                'App\Models\Procurement\Crawler\Proxy',
                'Illuminate\Support\Facades\DB',
            ],
            'database/seeders/BaseSeeder.php' => [
                'Database\\Seeders\\Platforms\\Eis\\EisCrawlerProfileSeeder',
                'Database\Seeders\Platforms\Eis\EisPlatformSeeder',
            ],
            'database/migrations/2026_07_04_100017_create_prc_eis_details_table.php' => [
                'App\Models\Platforms\Eis\EisDetails',
            ],
        ];
    }

    public static function platformCode(string $path): ?string
    {
        if (str_starts_with($path, 'tests/Support/Platforms/Contract/')) {
            return null;
        }

        return preg_match('~^(?:app/[^/]+|database/(?:factories|seeders)|tests/Support)/Platforms/([^/]+)/~', $path, $matches) === 1
            ? $matches[1] : null;
    }

    public static function dependencyPlatform(string $dependency): ?string
    {
        if (str_starts_with($dependency, 'Tests\\Support\\Platforms\\Contract\\')) {
            return null;
        }

        return preg_match('/^(?:App\\\\[^\\\\]+|Database\\\\(?:Factories|Seeders)|Tests\\\\Support)\\\\Platforms\\\\([^\\\\]+)\\\\/', $dependency, $matches) === 1
            ? $matches[1] : null;
    }

    /** @return list<string> */
    public static function forbiddenDependencies(string $path, string $source): array
    {
        $inspection = self::inspect($source);
        $platform = self::platformCode($path);
        $permissions = self::rolePermissions()[$path] ?? [];
        $forbidden = [];

        foreach ($inspection['dependencies'] as $dependency) {
            $dependencyPlatform = self::dependencyPlatform($dependency);

            if ($platform !== null && $dependencyPlatform !== null && $platform !== $dependencyPlatform) {
                $forbidden[] = $dependency;

                continue;
            }

            if (in_array($dependency, $permissions, strict: true)) {
                continue;
            }

            if ($platform === null) {
                if ($dependencyPlatform !== null) {
                    $forbidden[] = $dependency;
                }

                continue;
            }

            if ($dependencyPlatform === $platform
                || preg_match('/^App\\\\(?:Dto|Enums|Exceptions)\\\\/', $dependency) === 1
                || str_starts_with($dependency, 'App\Services\Procurement\Platform\Contracts\\')) {
                continue;
            }

            // Ordinary framework helpers are allowed; transport ownership remains a legacy violation.
            if (str_starts_with($dependency, 'App\\')
                || in_array($dependency, ['Illuminate\Support\Facades\Http', 'Illuminate\Support\Facades\DB'], strict: true)
                || str_starts_with($dependency, 'GuzzleHttp\\')) {
                $forbidden[] = $dependency;
            }
        }

        return $forbidden;
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<array{file: string, import: string, owner: string}>  $baseline
     * @return list<string>
     */
    public static function dependencyViolations(array $sources, array $baseline): array
    {
        $violations = [];
        $actual = [];
        $expected = [];

        foreach ($baseline as $entry) {
            $expected[] = $entry['file'].' -> '.$entry['import'];
        }

        foreach ($sources as $path => $source) {
            $inspection = self::inspect($source);

            if ($inspection['group_use']) {
                $violations[] = 'group use is not supported: '.$path;
            }

            if (str_starts_with($path, 'app/Filament/Platforms/') && $inspection['writes']) {
                $violations[] = 'UI process write: '.$path;
            }

            foreach (self::forbiddenDependencies($path, $source) as $dependency) {
                $key = $path.' -> '.$dependency;
                $platform = self::platformCode($path);
                $dependencyPlatform = self::dependencyPlatform($dependency);

                if ($platform !== null && $dependencyPlatform !== null && $platform !== $dependencyPlatform) {
                    $violations[] = 'cross-platform dependency: '.$key;
                } else {
                    $actual[] = $key;
                }
            }
        }

        foreach (array_diff($actual, $expected) as $key) {
            $violations[] = 'forbidden dependency: '.$key;
        }

        foreach (array_diff($expected, $actual) as $key) {
            $violations[] = 'stale baseline: '.$key;
        }

        sort($violations, SORT_STRING);

        return $violations;
    }

    /**
     * @param  list<array{file: string, import: string, owner: string}>  $baseline
     * @param  list<array{file: string, import: string, owner: string}>  $origin
     * @return list<string>
     */
    public static function baselineGrowthViolations(array $baseline, array $origin): array
    {
        $violations = [];
        $seen = [];

        foreach ($baseline as $entry) {
            $key = $entry['file'].' -> '.$entry['import'];

            if (isset($seen[$key])) {
                $violations[] = 'duplicate baseline pair: '.$key;
            }

            $seen[$key] = true;

            if (! in_array($entry, $origin, strict: true)) {
                $violations[] = 'expanded baseline or changed owner: '.$key;
            }
        }

        sort($violations, SORT_STRING);

        return $violations;
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    public static function layoutViolations(string $path, string $source, array $codes): array
    {
        $platform = self::platformCode($path);

        $contractKit = preg_match('~^tests/Support/Platforms/Contract/[^/]+\.php$~', $path) === 1;
        if (! $contractKit && ($platform === null || ! in_array($platform, $codes, strict: true))) {
            return ['unknown platform directory: '.$path];
        }

        $namespacePath = preg_replace('~^database/factories/~', 'Database/Factories/', $path);
        $namespacePath = preg_replace('~^database/seeders/~', 'Database/Seeders/', (string) $namespacePath);
        $namespacePath = preg_replace('~^app/~', 'App/', (string) $namespacePath);
        $namespacePath = preg_replace('~^tests/~', 'Tests/', (string) $namespacePath);
        $expected = str_replace('/', '\\', dirname((string) $namespacePath));
        $violations = [];

        if (self::inspect($source)['namespace'] !== $expected) {
            $violations[] = 'namespace/path mismatch: '.$path.' -> '.$expected;
        }

        if (str_starts_with($path, 'app/Services/Platforms/')) {
            $relative = substr($path, strlen('app/Services/Platforms/'.$platform.'/'));
            $parts = explode('/', $relative);
            $allowed = ['Card', 'Csv', 'Listing', 'Status', 'Documents', 'Filters', 'Validators', 'Diagnostics', 'Persistence'];

            if (count($parts) > 2 || (count($parts) === 2 && ! in_array($parts[0], $allowed, strict: true))) {
                $violations[] = 'unsupported platform service directory: '.$path;
            }
        }

        return $violations;
    }
}
