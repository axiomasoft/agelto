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
        ['attempts-page', 'filament.admin.pages.attempts-page', 'Proxyma\\Filament\\Pages\\AttemptsPage'],
        ['audit-page', 'filament.admin.pages.audit-page', 'Proxyma\\Filament\\Pages\\AuditPage'],
        ['bindings-page', 'filament.admin.pages.bindings-page', 'Proxyma\\Filament\\Pages\\BindingsPage'],
        ['budgets-page', 'filament.admin.pages.budgets-page', 'Proxyma\\Filament\\Pages\\BudgetsPage'],
        ['health-page', 'filament.admin.pages.health-page', 'Proxyma\\Filament\\Pages\\HealthPage'],
        ['policy-inspector', 'filament.admin.pages.policy-inspector', 'Proxyma\\Filament\\Pages\\PolicyInspector'],
        ['proxyma-endpoints', 'filament.admin.pages.proxyma-endpoints', 'Proxyma\\Filament\\Resources\\Pages\\Endpoints'],
        ['proxyma-profiles', 'filament.admin.pages.proxyma-profiles', 'Proxyma\\Filament\\Resources\\Pages\\RouteProfiles'],
        ['proxyma-routes', 'filament.admin.pages.proxyma-routes', 'Proxyma\\Filament\\Resources\\Pages\\Routes'],
        ['azguard-doctor', 'filament.admin.pages.azguard-doctor', 'AzGuard\Filament\Pages\DoctorPage'],
        ['azguard-panels', 'filament.admin.pages.azguard-panels', 'AzGuard\Filament\Pages\PanelsPage'],
        ['azguard-permission-grants', 'filament.admin.resources.azguard-permission-grants.index', 'AzGuard\Filament\Resources\PermissionGrantResource\Pages\ListPermissionGrants'],
        ['azguard-permissions', 'filament.admin.resources.azguard-permissions.index', 'AzGuard\Filament\Resources\PermissionResource\Pages\ListPermissions'],
        ['azguard-role-grants', 'filament.admin.resources.azguard-role-grants.index', 'AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants'],
        ['azguard-roles', 'filament.admin.resources.azguard-roles.index', 'AzGuard\Filament\Resources\RoleResource\Pages\ListRoles'],
        ['azguard-roles/{panel}/{role}', 'filament.admin.resources.azguard-roles.view', 'AzGuard\Filament\Resources\RoleResource\Pages\ViewRole'],
        ['procurement/process/control-scopes', 'filament.admin.resources.procurement.process.control-scopes.index', 'App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes'],
        ['procurement/process/incidents', 'filament.admin.resources.procurement.process.incidents.index', 'App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents'],
        ['procurement/process/runs/process-runs', 'filament.admin.resources.procurement.process.runs.process-runs.index', 'App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns'],
        ['procurement/process/work/work-items', 'filament.admin.resources.procurement.process.work.work-items.index', 'App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems'],
        ['procurement/platform/parsing-rule-sets', 'filament.admin.resources.procurement.platform.parsing-rule-sets.index', 'App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\ListParsingRuleSets'],
        ['procurement/platform/parsing-rule-sets/create', 'filament.admin.resources.procurement.platform.parsing-rule-sets.create', 'App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\CreateParsingRuleSet'],
        ['procurement/platform/parsing-rule-sets/{record}', 'filament.admin.resources.procurement.platform.parsing-rule-sets.view', 'App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\ViewParsingRuleSet'],
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
        'filament.admin.pages.crawler-proxy-settings',
        'filament.admin.resources.procurement.crawler.proxies.index',
        'filament.admin.resources.procurement.crawler.proxies.create',
        'filament.admin.resources.procurement.crawler.proxies.edit',
        'filament.admin.resources.direct-grants.index',
        'filament.admin.resources.direct-grants.create',
        'filament.admin.pages.doctor-page',
        'filament.admin.resources.roles.index',
        'filament.admin.resources.roles.create',
        'filament.admin.resources.roles.edit',
        'filament.admin.resources.procurement.crawler.source-incidents.index',
        'filament.admin.resources.procurement.crawler.source-circuits.edit',
        'filament.admin.resources.procurement.crawler.source-circuits.index',
        'filament.admin.resources.procurement.crawler.runs.index',
        'filament.admin.resources.procurement.crawler.runs.view',
    ];
    expect(array_column($historical, 'name'))->toContain(...$retired);
    // Explicit /delta; the frozen historical matrix remains byte-identical.
    $current = array_values(array_filter($historical, static fn (array $route): bool => ! in_array($route['name'], $retired, true)));
    $matrixRoutes = [...$current, ...declaredAdminRouteExtensions()];
    usort($matrixRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);
    usort($runtimeRoutes, static fn (array $a, array $b): int => [$a['uri'], $a['name']] <=> [$b['uri'], $b['name']]);

    expect($runtimeRoutes)
        ->toHaveCount(69)
        ->toEqual($matrixRoutes)
        ->and(array_column($runtimeRoutes, 'name'))
        ->toHaveCount(count(array_unique(array_column($runtimeRoutes, 'name'))));
});
