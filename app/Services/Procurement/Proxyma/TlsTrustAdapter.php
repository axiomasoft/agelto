<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Services\Procurement\SourceTlsTrust;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Infrastructure\Database\ConnectionScope;

final readonly class TlsTrustAdapter implements TlsTrustPolicy
{
    public function __construct(private SourceTlsTrust $trust, private ConnectionScope $scope) {}

    public function tlsConfigurationFor(CanonicalOrigin $origin, Uuid $targetId): TlsTrustConfiguration
    {
        return $this->configuration($origin->host);
    }

    public function proxyConfigurationFor(string $originalHost): TlsTrustConfiguration
    {
        return $this->configuration($originalHost);
    }

    private function configuration(string $host): TlsTrustConfiguration
    {
        $this->scope->assertOutsideTransaction();

        $bundle = $this->trust->optionsFor($host)['verify'] ?? null;
        $digest = $bundle === null ? false : @hash_file('sha256', $bundle);

        return new TlsTrustConfiguration(caBundlePath: $bundle,
            caBundleSha256: $digest === false ? null : new ContentHash($digest));
    }
}
