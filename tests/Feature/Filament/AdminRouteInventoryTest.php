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

/**
 * Маршруты, добавленные после неизменяемого снимка матрицы (authentic61, D24): исторический fixture
 * не переписывается, расширения объявляются здесь явно и поштучно.
 *
 * @return list<array{method: string, uri: string, name: string, action: string}>
 */
function declaredAdminRouteExtensions(): array
{
    $routes = [
        ['procurement/process/control-scopes', 'filament.admin.resources.procurement.process.control-scopes.index', 'App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes'],
        ['procurement/process/incidents', 'filament.admin.resources.procurement.process.incidents.index', 'App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents'],
    ];

    return array_map(
        static fn (array $route): array => ['method' => 'GET|HEAD', 'uri' => 'admin/'.$route[0], 'name' => $route[1], 'action' => $route[2]],
        $routes,
    );
}

test('runtime admin routes exactly match committed resource and role matrix', function (): void {
    $runtimeRoutes = adminRouteInventory();
    $matrixRoutes = [...committedAdminRouteInventory(), ...declaredAdminRouteExtensions()];
    usort($matrixRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);
    usort($runtimeRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);

    expect($runtimeRoutes)
        ->toHaveCount(63)
        ->toEqual($matrixRoutes)
        ->and(array_column($runtimeRoutes, 'name'))
        ->toHaveCount(count(array_unique(array_column($runtimeRoutes, 'name'))));
});
