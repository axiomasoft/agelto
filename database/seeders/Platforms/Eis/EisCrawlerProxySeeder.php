<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\ScopeKind;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/** Imports the local RU listing proxy without embedding credentials in source. */
final class EisCrawlerProxySeeder extends Seeder
{
    public const string SERVICE = 'ЕИС — RU CSV audit';

    public function run(): void
    {
        $url = env('PLAYWRIGHT_MCP_PROXY_SERVER');
        $parts = is_string($url) ? parse_url($url) : false;

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || empty($parts['host'])
            || ! isset($parts['port'])
            || $parts['port'] < 1
            || $parts['port'] > 65535
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], strict: true)
        ) {
            throw new RuntimeException('PLAYWRIGHT_MCP_PROXY_SERVER must contain a valid HTTP proxy URL with an explicit port.');
        }

        app(ProcessTransaction::class)->run(function () use ($parts): void {
            app(ControlRepository::class)->ensureScopes([
                new ScopeDefinition(ScopeKind::System, 'system', null),
            ]);
            $proxy = Proxy::query()->where('service', self::SERVICE)->first();
            $configuration = new ProxyNodeConfiguration(
                service: self::SERVICE, host: $parts['host'], port: $parts['port'],
                username: isset($parts['user']) ? rawurldecode($parts['user']) : null,
                country: 'RU', scopes: [ProxyScope::CrawlListing], secretRef: 'PLAYWRIGHT_MCP_PROXY_SERVER',
                enabled: $proxy?->route?->enabled ?? true,
            );
            // Explicit reseeding revokes the previous credential admission, including a
            // changed value behind the same secret reference. No secret fingerprint is stored.
            $proxy = app(RouteRepository::class)->storeProxy($proxy, $configuration, null,
                'proxy_catalog_install', new ProcessUuid((string) Str::uuid()),
                new ProcessUuid((string) Str::uuid()));
            $routes = app(RouteRepository::class);
            $routes->ensureProfile('eis.audit', $proxy->route_id);
        });
    }
}
