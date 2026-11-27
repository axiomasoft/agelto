<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Проверяет production source, включая новые файлы; history exceptions заданы по точному пути. */
final class ProxymaOwnershipRules
{
    public const array TABLES = ['prc_proxies', 'prc_network_routes', 'prc_network_route_profiles',
        'prc_network_route_profile_members', 'prc_network_budgets', 'prc_network_demands', 'prc_network_permits',
        'prc_network_permit_scopes', 'prc_route_health', 'prc_route_target_health', 'prc_target_health', 'prc_request_attempts'];

    public const array RETIRED = [
        'Services/Procurement/Proxy/ProxyResolver', 'Services/Procurement/Proxy/RouteSelector', 'Services/Procurement/Proxy/RoutePolicy',
        'Services/Procurement/Network/HttpTransport', 'Services/Procurement/Network/OutboundExecutor',
        'Services/Procurement/Network/HealthReducer', 'Services/Procurement/Network/RequestClassifier',
        'Repositories/Procurement/Network/RouteRepository', 'Repositories/Procurement/Network/HealthRepository',
        'Repositories/Procurement/Network/NetworkPermitRepository', 'Repositories/Procurement/Network/NetworkAuthorityRepository',
        'Services/Procurement/Proxyma/CallerAttemptGate',
        'Filament/Pages/NetworkRouteProfilesPage', 'Filament/Resources/Procurement/Crawler/Proxies/ProxyResource',
    ];

    private const array HISTORY = [
        'app/Repositories/Procurement/Document/DownloadStorageRepository.php',
        'app/Repositories/Procurement/Network/AdapterHealthSqlHelper.php',
        'app/Services/Procurement/Acquisition/Diagnostics/AcquisitionDiagnostic.php',
        'app/Services/Procurement/Proxyma/Migration/Inventory.php',
        'app/Services/Procurement/Proxyma/Migration/TransferCatalog.php',
        'app/Services/Procurement/Proxyma/Migration/TransferState.php',
        'app/Services/Procurement/Proxyma/Migration/Cutover.php',
        'app/Services/Procurement/Proxyma/Migration/HistoricalRequests.php',
        'app/Services/Procurement/Proxyma/Migration/HistoricalCatalog.php',
    ];

    /** @return list<string> */
    public static function violations(string $path, string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $strings = $finder->findInstanceOf($nodes, Node\Scalar\String_::class);
        $errors = [];
        $history = in_array($path, self::HISTORY, true);
        $carrierNames = ['Proxy', 'NetworkRoute', 'NetworkRouteProfile', 'NetworkBudget', 'NetworkDemand', 'NetworkPermit',
            'RouteHealth', 'RouteTargetHealth', 'TargetHealth', 'ProfileMember', 'PermitScope'];
        $carrier = in_array($path, array_map(static fn (string $name): string => 'app/Models/Procurement/Proxyma/Legacy'.$name.'.php', $carrierNames), true)
            || $path === 'app/Models/Procurement/Crawler/Proxy.php';
        $migration = str_starts_with($path, 'database/migrations/');
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $proxy = 'App\\Models\\Procurement\\Crawler\\Proxy';
        $dependencies = PlatformSourceRules::inspect($source)['dependencies'];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\GroupUse::class) as $group) {
            foreach ($group->uses as $import) {
                $dependencies[] = $group->prefix->toString().'\\'.$import->name->toString();
            }
        }
        if ((str_contains($code, $proxy) || in_array($proxy, $dependencies, true)) && $path !== 'database/migrations/2026_07_04_100040_create_prc_proxies_table.php') {
            $errors[] = 'proxy_runtime_reference';
        }
        $forwardCarriers = [
            'database/migrations/2026_11_11_100000_create_prc_proxyma_adapter_links.php',
            'database/migrations/2026_11_11_100001_create_prc_proxyma_transfer_records.php',
            'database/migrations/2026_11_11_100002_add_prc_proxyma_cutover_fences.php',
        ];
        if (! $carrier && ! in_array($path, $forwardCarriers, true)
            && (preg_match('/App\\\\Models\\\\Procurement\\\\Proxyma\\\\Legacy[A-Za-z]+/', $code)
                || array_any($dependencies, static fn (string $name): bool => str_starts_with($name, 'App\\Models\\Procurement\\Proxyma\\Legacy')))) {
            $errors[] = 'carrier_runtime_reference';
        }
        foreach (self::RETIRED as $retired) {
            if (str_contains($code, 'App\\'.str_replace('/', '\\', $retired)) || in_array('App\\'.str_replace('/', '\\', $retired), $dependencies, true)) {
                $errors[] = 'retired_runtime_reference';
            }
        }
        if (! $migration && ! $carrier) {
            foreach ($strings as $literal) {
                foreach (self::TABLES as $table) {
                    if (! preg_match('/\b'.preg_quote($table, '/').'\b/i', $literal->value)) {
                        continue;
                    }
                    if (! $history) {
                        $errors[] = 'unowned_legacy_table';
                    }
                    if (preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?|DROP\s+TABLE|ALTER\s+TABLE)\s+["`]?'.preg_quote($table, '/').'\b/i', $literal->value)) {
                        $errors[] = 'legacy_sql_write';
                    }
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier || ! in_array($call->name->name,
                    ['insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'update', 'updateOrInsert', 'delete', 'truncate', 'increment', 'decrement'], true)) {
                    continue;
                }
                $root = $call->var;
                while ($root instanceof Node\Expr\MethodCall && ! ($root->name instanceof Node\Identifier && $root->name->name === 'table')) {
                    $root = $root->var;
                }
                if (($root instanceof Node\Expr\StaticCall || $root instanceof Node\Expr\MethodCall) && $root->name instanceof Node\Identifier && $root->name->name === 'table') {
                    $table = $root->args[0]->value ?? null;
                    if ($table instanceof Node\Scalar\String_ && array_any(self::TABLES,
                        static fn (string $name): bool => preg_match('/^'.preg_quote($name, '/').'\b/', $table->value) === 1)) {
                        $errors[] = 'legacy_builder_write';
                    }
                }
            }
        }
        if (! $migration && preg_match('~^(?:app/Services/(?:Procurement|Platforms|Llm)/|app/Notifications/)~', $path)) {
            $approvedCurl = $path === 'app/Services/Procurement/Proxyma/Transport/AgeltoPhysicalHttpTransport.php';
            if (array_intersect($dependencies, ['Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client\\PendingRequest', 'GuzzleHttp\\Client', 'GuzzleHttp\\ClientInterface', 'Psr\\Http\\Client\\ClientInterface']) !== []
                || preg_match('/(?:Facades\\\\Http|PendingRequest|GuzzleHttp\\\\Client\b|\b(?:dns_get_record|stream_socket_client|fsockopen)\s*\()/i', $code)
                || (! $approvedCurl && preg_match('/\bcurl_[a-z_]+\s*\(/i', $code))) {
                $errors[] = 'direct_transport';
            }
        }

        return array_values(array_unique($errors));
    }
}
