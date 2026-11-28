<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use App\Services\Procurement\SourceTlsTrust;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Http\TlsTrust;

final readonly class TlsTrustAdapter implements TlsTrustPolicy
{
    public function __construct(private SourceTlsTrust $trust, private ConnectionScope $scope) {}

    public function tlsConfigurationFor(CanonicalOrigin $origin, Uuid $targetId): TlsTrustConfiguration
    {
        return (new TlsTrust(scope: $this->scope, originBundles: $this->bundlesFor($origin->host)))
            ->tlsConfigurationFor(origin: $origin, targetId: $targetId);
    }

    public function proxyConfigurationFor(string $originalHost): TlsTrustConfiguration
    {
        $originalHost = trim($originalHost);

        return (new TlsTrust(scope: $this->scope, proxyBundles: $this->bundlesFor($originalHost)))
            ->proxyConfigurationFor($originalHost);
    }

    /** @return array<string, string> */
    private function bundlesFor(string $host): array
    {
        $this->scope->assertOutsideTransaction();

        $bundle = $this->trust->optionsFor($host)['verify'] ?? null;

        return $bundle === null ? [] : [$host => $bundle];
    }
}
