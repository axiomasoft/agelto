<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use Proxyma\Domain\Catalog\Endpoint;
use Proxyma\Domain\Execution\ProxyDestination;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Http\TransportDoctor;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

final readonly class ProxyResolver
{
    /** @param array<string, list<string>> $privateProxies Exact proxy host and canonical IP bindings. */
    public function __construct(private DnsResolver $dns, private array $privateProxies = []) {}

    public function resolve(Endpoint $endpoint, int $timeoutMs): ProxyDestination
    {
        return ProxyDestination::fromResolver($this, $endpoint, $timeoutMs);
    }

    public function validatedAddress(Endpoint $endpoint, int $timeoutMs): string
    {
        TransportDoctor::assertProtocol($endpoint->protocol);
        /** @var list<mixed> $addresses */
        $addresses = $this->dns->resolve(host: $endpoint->host, timeoutMs: $timeoutMs);
        if ($addresses === []) {
            throw new RuntimeException('Proxy DNS returned no addresses.');
        }

        foreach ($addresses as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('Proxy DNS returned an invalid address.');
            }
            $canonical = (string) inet_ntop((string) inet_pton($address));
            if (! self::isPublic($canonical) && ! in_array($canonical, $this->privateProxies[$endpoint->host] ?? [], true)) {
                throw new RuntimeException('Proxy address denied.');
            }
        }

        return $addresses[0];
    }

    private static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! IpUtils::checkIp(requestIp: $address, ips: [
                '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
                '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '2001:db8::/32', '2002::/16', 'ff00::/8',
            ]);
    }
}
