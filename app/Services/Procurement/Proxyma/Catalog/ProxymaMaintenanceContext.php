<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Catalog;

use Closure;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use stdClass;

final class ProxymaMaintenanceContext
{
    public const string RECONCILE = 'execution.reconcile';

    public const string PRUNE = 'execution.retention.prune';

    private ?object $owner = null;

    private ?string $ability = null;

    public function context(): ?TenantContext
    {
        return $this->owner === null ? null : new TenantContext(TenantKey::system(), new ActorRef('worker', 'proxyma-maintenance'));
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $ability, Closure $callback): mixed
    {
        $owner = new stdClass;
        $this->begin(ability: $ability, owner: $owner);

        try {
            return $callback();
        } finally {
            $this->end($owner);
        }
    }

    public function begin(string $ability, object $owner): void
    {
        if ($this->owner !== null || ! in_array($ability, [self::RECONCILE, self::PRUNE], true)) {
            throw new AuthorizationDenied;
        }

        $this->owner = $owner;
        $this->ability = $ability;
    }

    public function end(object $owner): void
    {
        if ($this->owner === $owner) {
            $this->owner = null;
            $this->ability = null;
        }
    }

    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability): void
    {
        $context = $this->context();
        if ($context === null || ! $tenant->isSystem() || $context->actor->type !== $actor->type
            || $context->actor->id !== $actor->id || $this->ability !== $ability) {
            throw new AuthorizationDenied;
        }
    }
}
