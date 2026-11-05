<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\ResolvedUrl;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Process\ExecutionContext;
use Symfony\Component\HttpFoundation\IpUtils;

/** Exact origin/port allowlist, all DNS answers checked, then connect bound to a checked address. */
final readonly class UrlPolicy
{
    /**
     * @param  list<CanonicalOrigin>  $origins
     * @param  array<string, list<string>>  $fixtureBindings  Only isolated test rig, never production.
     * @param  (\Closure(string, int, ExecutionContext|OutboundExecutionContext|null): list<string>)|null  $dnsResolver
     */
    public function __construct(private array $origins, private array $fixtureBindings = [], private ?\Closure $dnsResolver = null)
    {
        if ($origins === []) {
            throw new \InvalidArgumentException('URL policy needs explicit origins.');
        }
        if (($fixtureBindings !== [] || $this->dnsResolver !== null) && (! app()->environment('testing') || getenv('PROCESS_CORE_TEST') !== '1')) {
            throw new \LogicException('Local DNS bindings require the isolated test rig.');
        }
    }

    public function resolve(SafeUrl $url, ExecutionContext|OutboundExecutionContext|null $context = null, int $dnsTimeoutSeconds = 5): ResolvedUrl
    {
        if (! array_any($this->origins, fn (CanonicalOrigin $origin): bool => $origin->equals($url->origin))) {
            throw new \InvalidArgumentException('Origin denied by URL policy.');
        }
        $host = $url->origin->host;
        $addresses = $this->fixtureBindings[$host] ?? null;
        $fixture = $addresses !== null;
        if ($addresses === null) {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $addresses = [$host];
            } elseif ($this->dnsResolver !== null) {
                $addresses = ($this->dnsResolver)($host, $dnsTimeoutSeconds, $context);
            } else {
                $addresses = (new BoundedDnsResolver)->resolve($host, $dnsTimeoutSeconds, $context);
            }
        }
        if ($addresses === []) {
            throw new \RuntimeException('DNS has no usable addresses.');
        }
        $validated = [];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || (! $fixture && ! self::publicAddress($address))) {
                throw new \InvalidArgumentException('Non-public address denied by URL policy.');
            }
            if (! in_array($address, $validated, true)) {
                $validated[] = $address;
            }
        }

        return new ResolvedUrl($url, $validated[0], $validated);
    }

    private static function publicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/96', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'ff00::/8']);
    }
}
