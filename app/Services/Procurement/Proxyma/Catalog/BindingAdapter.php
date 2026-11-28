<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Catalog;

use App\Dto\Procurement\Acquisition\Control\AcquisitionConfiguration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Proxyma\BindingLink;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Models\Procurement\Proxyma\ProfileLink;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Procurement\Proxyma\Migration\HistoricalCatalog;
use Illuminate\Support\Facades\DB;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\SubjectResolver;
use Proxyma\Domain\Catalog\RouteProfile;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;

final readonly class BindingAdapter implements SubjectResolver
{
    public function __construct(private ProxyBindingResolver $legacy, private CatalogQueries $catalog, private ConnectionScope $scope) {}

    public function forCrawler(Crawler $crawler): RouteProfile
    {
        return $this->activeProfile($this->legacy->forCrawler($crawler)->profileId);
    }

    public function forProcurement(?int $procurementId): RouteProfile
    {
        return $this->activeProfile($this->legacy->forProcurement($procurementId)->profileId);
    }

    /** @return array{profile_id: string, profile_version: int} */
    public function snapshotProfile(AcquisitionConfiguration $snapshot, StageCode $stage): array
    {
        return $this->mapping($snapshot->routeProfileIdForStage($stage), $snapshot->routePolicyVersionForStage($stage));
    }

    public function binding(int $crawlerId, string $slot): Uuid
    {
        $id = $this->scope->connection->table((new BindingLink)->getTable())->where('tenant_key', TenantKey::SYSTEM)
            ->where('crawler_id', $crawlerId)->where('slot', $slot)->value('binding_id');

        return is_string($id) ? new Uuid($id) : throw new \DomainException('Missing crawler binding mapping.');
    }

    public function resolve(SubjectRef $leaf): ?SubjectChain
    {
        if (! $leaf->tenant->isSystem() || $leaf->parents !== []) {
            return null;
        }
        $db = $this->scope->connection;
        if ($leaf->type === 'system') {
            return $leaf->id === 'network' ? new SubjectChain($leaf) : null;
        }
        if ($leaf->type === 'provider') {
            return $db->table('llm_providers')->where('code', $leaf->id)->where('is_active', true)->exists() ? new SubjectChain($leaf) : null;
        }
        if ($leaf->type === 'platform') {
            return $db->table('prc_platforms')->where('id', $leaf->id)->exists() ? new SubjectChain($leaf) : null;
        }
        if ($leaf->type === 'registry') {
            $platform = $db->table('prc_registries')->where('id', $leaf->id)->value('platform_id');

            return ! is_int($platform) && ! is_string($platform) ? null : new SubjectChain($leaf, [new SubjectRef($leaf->tenant, 'platform', (string) $platform)]);
        }
        $table = match ($leaf->type) {
            'crawler' => 'prc_crawlers',
            'procurement' => 'prc_procurements',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $row = $db->table($table.' as s')->join('prc_registries as r', 'r.id', '=', 's.registry_id')
            ->where('s.id', $leaf->id)->first(['r.id', 'r.platform_id']);

        if ($row === null || (! is_int($row->id) && ! is_string($row->id))
            || (! is_int($row->platform_id) && ! is_string($row->platform_id))) {
            return null;
        }

        return new SubjectChain($leaf, [
            new SubjectRef($leaf->tenant, 'platform', (string) $row->platform_id),
            new SubjectRef($leaf->tenant, 'registry', (string) $row->id),
        ]);
    }

    public function activeProfile(?ProcessUuid $source): RouteProfile
    {
        $this->scope->assertSame(DB::connection());
        if ($source === null) {
            throw new \DomainException('Missing route profile mapping.');
        }
        $id = $this->scope->connection->table((new ProfileLink)->getTable().' as l')
            ->join(PackageSchema::tables()['route_profiles'].' as p', 'p.id', '=', 'l.profile_id')
            ->where('l.tenant_key', TenantKey::SYSTEM)->where('p.tenant_key', TenantKey::SYSTEM)->whereNull('p.retired_at')
            ->where('l.source_profile_id', $source->value)->orderByDesc('l.source_version')->value('l.profile_id');
        $profile = is_string($id) ? $this->catalog->findProfile(TenantKey::system(), new Uuid($id)) : null;
        if ($profile === null) {
            throw new \DomainException('Missing or retired active route profile mapping.');
        }

        return $profile;
    }

    /** @return array{profile_id: string, profile_version: int} */
    private function mapping(ProcessUuid $source, int $version): array
    {
        $this->scope->assertSame(DB::connection());
        $row = LedgerRow::one('SELECT profile_id, profile_version FROM '.(new ProfileLink)->getTable()
            .' WHERE tenant_key=? AND source_profile_id=? AND source_version=?', [TenantKey::SYSTEM, $source->value, $version]);
        if ($row !== null) {
            return ['profile_id' => $row->string('profile_id'), 'profile_version' => $row->int('profile_version')];
        }
        if (DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') !== 'proxyma') {
            $profile = HistoricalCatalog::profile($source, '');
            if ($profile === null || $profile->int('policy_version') !== $version) {
                throw new \DomainException('Historical snapshot profile revision mismatch.');
            }

            return ['profile_id' => $source->value, 'profile_version' => $version];
        }
        throw new \DomainException('Missing immutable acquisition profile mapping.');
    }
}
