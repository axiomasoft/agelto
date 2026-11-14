<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Process\ExecutionContext;

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

    public function allows(SafeUrl $url): bool
    {
        return array_any($this->origins, static fn (CanonicalOrigin $origin): bool => $origin->equals($url->origin));
    }
}
