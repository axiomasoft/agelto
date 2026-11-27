<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Catalog;

use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Models\Procurement\Proxyma\TargetLink;
use Illuminate\Database\Query\Builder;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Catalog\CanonicalOrigin;
use Proxyma\Domain\Catalog\Target;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;

/**
 * @phpstan-type SourceRow object{id: string, platform_id: int|null, provider_key: string|null, capability: string, canonical_origin: string, policy_version: int, source_revision: int, target_id: string}
 */
final readonly class TargetCatalogAdapter
{
    public function __construct(private ConnectionScope $scope, private CatalogQueries $catalog) {}

    public function platform(string $code, string $key, string $capability): Target
    {
        $source = $this->first($this->source()->join('prc_platforms as p', 'p.id', '=', 's.platform_id')
            ->where('p.code', $code)->whereNull('s.provider_key')->where('s.target_key', $key)->where('s.capability', $capability));

        return $this->mapped($source);
    }

    public function provider(ProviderKey $provider, string $key, string $capability = 'provider'): Target
    {
        $source = $this->first($this->source()->where('s.provider_key', $provider->value)->whereNull('s.platform_id')
            ->where('s.target_key', $key)->where('s.capability', $capability));

        return $this->mapped($source);
    }

    public function redirect(Uuid $from, Uuid $to, CanonicalOrigin $origin): Target
    {
        $source = $this->first($this->source()->where('l.target_id', $from->value));
        $destination = $this->first($this->source()->where('l.target_id', $to->value));
        $this->mapped($source);
        if ($source === null || $destination === null || $source->platform_id !== $destination->platform_id
            || $source->provider_key !== $destination->provider_key || $source->capability !== $destination->capability
            || ! CanonicalOrigin::fromUrl($destination->canonical_origin)->equals($origin)) {
            throw new \DomainException('Redirect target ownership, capability or origin mismatch.');
        }

        return $this->mapped($destination);
    }

    /** @return SourceRow|null */
    public function sourceFor(Uuid $target): ?object
    {
        return $this->first($this->source()->where('l.target_id', $target->value));
    }

    /** @return SourceRow|null */
    private function first(Builder $query): ?object
    {
        /** @var SourceRow|null $row */
        $row = $query->first(['s.*', 'l.target_id', 'l.source_revision']);

        return $row;
    }

    private function source(): Builder
    {
        return $this->scope->connection->table((new TargetLink)->getTable().' as l')
            ->join('prc_source_targets as s', 's.id', '=', 'l.source_target_id')->where('l.tenant_key', TenantKey::SYSTEM)->whereNull('s.retired_at');
    }

    /** @param SourceRow|null $source */
    private function mapped(?object $source): Target
    {
        $target = $source === null ? null : $this->catalog->findTarget(TenantKey::system(), new Uuid($source->target_id));
        if ($source === null || $target === null || $target->isRetired()
            || (int) $source->source_revision !== (int) $source->policy_version
            || ! $target->origin->equals(CanonicalOrigin::fromUrl($source->canonical_origin))) {
            throw new \DomainException('Missing, stale or retired target mapping.');
        }

        return $target;
    }
}
