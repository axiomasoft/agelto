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
    $matrix = file_get_contents(base_path('tests/Fixtures/ProcessCore/historical/P9-admin-route-role-matrix.md'));

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
 * Маршруты, добавленные после неизменяемого снимка матрицы (authentic61): исторический fixture
 * не переписывается, расширения объявляются здесь явно и поштучно.
 *
 * @return list<array{method: string, uri: string, name: string, action: string}>
 */
function declaredAdminRouteExtensions(): array
{
    $routes = [
        ['procurement/process/control-scopes', 'filament.admin.resources.procurement.process.control-scopes.index', 'App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes'],
        ['procurement/process/incidents', 'filament.admin.resources.procurement.process.incidents.index', 'App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents'],
        ['procurement/process/runs/process-runs', 'filament.admin.resources.procurement.process.runs.process-runs.index', 'App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns'],
        ['procurement/process/work/work-items', 'filament.admin.resources.procurement.process.work.work-items.index', 'App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems'],
    ];

    return array_map(
        static fn (array $route): array => ['method' => 'GET|HEAD', 'uri' => 'admin/'.$route[0], 'name' => $route[1], 'action' => $route[2]],
        $routes,
    );
}

test('runtime admin routes exactly match committed resource and role matrix', function (): void {
    $runtimeRoutes = adminRouteInventory();
    $historical = committedAdminRouteInventory();
    $retired = [
        'filament.admin.resources.procurement.crawler.source-incidents.index',
        'filament.admin.resources.procurement.crawler.source-circuits.edit',
        'filament.admin.resources.procurement.crawler.source-circuits.index',
        'filament.admin.resources.procurement.crawler.runs.index',
        'filament.admin.resources.procurement.crawler.runs.view',
    ];
    expect(array_column($historical, 'name'))->toContain(...$retired);
    // Explicit /delta; the frozen historical matrix remains byte-identical.
    $current = array_values(array_filter($historical, static fn (array $route): bool => ! in_array($route['name'], $retired, true)));
    foreach ($current as &$route) {
        if ($route['name'] === 'filament.admin.pages.crawler-proxy-settings') {
            $route['name'] = 'filament.admin.pages.network-route-profiles';
            $route['uri'] = 'admin/network-route-profiles';
            $route['action'] = str_replace('CrawlerProxySettingsPage', 'NetworkRouteProfilesPage', $route['action']);
        }
    }
    unset($route);
    $matrixRoutes = [...$current, ...declaredAdminRouteExtensions()];
    usort($matrixRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);
    usort($runtimeRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);

    expect($runtimeRoutes)
        ->toHaveCount(60)
        ->toEqual($matrixRoutes)
        ->and(array_column($runtimeRoutes, 'name'))
        ->toHaveCount(count(array_unique(array_column($runtimeRoutes, 'name'))));
});
