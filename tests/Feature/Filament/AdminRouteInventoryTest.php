<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function adminRouteInventory(): array
{
    Artisan::call('route:list', [
        '--path' => 'admin',
        '--json' => true,
    ]);

    /** @var list<array{method: string, uri: string, name: string, action: string}> $routes */
    $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return array_map(
        static fn (array $route): array => [
            'method' => $route['method'],
            'uri' => $route['uri'],
            'name' => $route['name'],
            'action' => $route['action'],
        ],
        $routes,
    );
}

function committedAdminRouteInventory(): array
{
    $matrix = file_get_contents(base_path('plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P9-admin-route-role-matrix.md'));

    preg_match_all(
        '/^\| `(?<method>[^`]+)` \| `(?<uri>[^`]+)` \| `(?<name>[^`]+)` \| `(?<action>[^`]+)` \|$/m',
        $matrix,
        $matches,
        PREG_SET_ORDER,
    );

    return array_map(
        static fn (array $route): array => [
            'method' => $route['method'],
            'uri' => $route['uri'],
            'name' => $route['name'],
            'action' => $route['action'],
        ],
        $matches,
    );
}

test('runtime admin routes exactly match committed resource and role matrix', function (): void {
    $runtimeRoutes = adminRouteInventory();
    $matrixRoutes = committedAdminRouteInventory();

    expect($runtimeRoutes)
        ->toHaveCount(58)
        ->toEqual($matrixRoutes)
        ->and(array_column($runtimeRoutes, 'name'))
        ->toHaveCount(count(array_unique(array_column($runtimeRoutes, 'name'))));
});
