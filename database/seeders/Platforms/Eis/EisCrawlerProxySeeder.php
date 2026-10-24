<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Database\Seeder;
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

        Proxy::query()->updateOrCreate(
            [
                'host' => $parts['host'],
                'port' => $parts['port'],
                'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            ],
            [
                'service' => self::SERVICE,
                'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
                // An egress check is still required; this label does not prove geography.
                'country' => 'RU',
                'scopes' => [ProxyScope::CrawlListing],
                'is_active' => true,
            ],
        );
    }
}
